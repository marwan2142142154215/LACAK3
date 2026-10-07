import { randomInt, randomUUID } from 'node:crypto'
import type { IncomingMessage, Server as HttpServer } from 'node:http'
import { isIP } from 'node:net'
import { WebSocket, WebSocketServer, type RawData } from 'ws'
import env from '#start/env'
import {
  callLaravelGateway,
  callLaravelService,
  GatewayUpstreamError,
} from '#services/laravel_gateway_client'
import { ensureRedisConnected, getRedisClient } from '#services/redis_client'

const MAX_FRAME_BYTES = 16 * 1024
const MAX_PREAUTH_MESSAGES = 5
const ACTIVE_SESSION_PREFIX = 'smb:gateway:active:'
const PRESENCE_PREFIX = 'smb:gateway:presence:'

type JsonRecord = Record<string, unknown>

type DeviceConnection = {
  socket: WebSocket
  deviceId: string
  deviceToken: string
  sessionId: string
  authenticatedAt: number
  lastHeartbeatAt: number | null
  preauthMessages: number
  acknowledgementQueue: Promise<void>
  closed: boolean
  observedIp: string | null
}

type OpenSessionResult = {
  device_id: string
  device_session_id: string
  session_generation: string
  credential_epoch: number
  capabilities: Record<string, boolean>
}

type HeartbeatResult = {
  accepted: boolean
  duplicate: boolean
  server_received_at: string
  device_id: string
  status: string
  connection_state: string
}

type ClaimedCommand = {
  outbox_id: number
  claim_id: string
  device_id: string
  command_id: string
  command_type: string
  sequence: number
  payload: JsonRecord
  expires_at: string
  attempt: number
}

const connectionsByDevice = new Map<string, DeviceConnection>()
const attemptsByIp = new Map<string, { count: number; expiresAt: number }>()
let gatewayServer: WebSocketServer | undefined
let gatewayReady = false
let keepaliveTimer: NodeJS.Timeout | undefined
let commandPollTimer: NodeJS.Timeout | undefined
let commandPollRunning = false
let commandPollBackoffMs = 2_000

function log(level: 'info' | 'warn' | 'error', message: string, fields: JsonRecord = {}) {
  process.stdout.write(
    `${JSON.stringify({
      timestamp: new Date().toISOString(),
      service: 'smb-gateway',
      level,
      message,
      ...fields,
    })}\n`
  )
}

function send(socket: WebSocket, type: string, data: JsonRecord = {}) {
  if (socket.readyState === WebSocket.OPEN) socket.send(JSON.stringify({ type, data }))
}

function parseJson(raw: RawData): unknown {
  const value = Buffer.isBuffer(raw)
    ? raw
    : Array.isArray(raw)
      ? Buffer.concat(raw)
      : Buffer.from(raw)
  if (value.byteLength > MAX_FRAME_BYTES) throw new Error('frame_too_large')
  return JSON.parse(value.toString('utf8')) as unknown
}

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function mayConnectFromIp(ip: string): boolean {
  const now = Date.now()
  const current = attemptsByIp.get(ip)
  if (!current || current.expiresAt <= now) {
    if (attemptsByIp.size > 10_000) {
      for (const [address, bucket] of attemptsByIp) {
        if (bucket.expiresAt <= now) attemptsByIp.delete(address)
      }
    }
    attemptsByIp.set(ip, { count: 1, expiresAt: now + 60_000 })
    return true
  }
  current.count += 1
  return current.count <= 120
}

function originAllowed(request: IncomingMessage): boolean {
  const origin = request.headers.origin
  if (!origin) return true
  const allowed = (env.get('WS_ALLOWED_ORIGINS') ?? '')
    .split(',')
    .map((item) => item.trim())
    .filter(Boolean)
  return allowed.includes(origin)
}

function cloudflareClientIp(request: IncomingMessage): string | null {
  const value = request.headers['cf-connecting-ip']
  return typeof value === 'string' && isIP(value) ? value : null
}

function validHeartbeat(payload: unknown): payload is JsonRecord {
  if (!isRecord(payload)) return false
  const capabilities = payload.capabilities
  if (!isRecord(capabilities)) return false
  const capabilityKeys = [
    'camera_available',
    'front_camera_available',
    'back_camera_available',
    'location_available',
    'managed_device',
    'device_owner',
    'foreground_service_available',
    'notification_permission',
  ]
  if (!capabilityKeys.every((key) => typeof capabilities[key] === 'boolean')) return false

  return (
    typeof payload.request_id === 'string' &&
    /^[0-9a-f-]{36}$/i.test(payload.request_id) &&
    typeof payload.reported_at === 'string' &&
    !Number.isNaN(Date.parse(payload.reported_at)) &&
    typeof payload.android_version === 'string' &&
    payload.android_version.length <= 32 &&
    Number.isInteger(payload.android_api) &&
    Number(payload.android_api) >= 26 &&
    Number(payload.android_api) <= 36 &&
    typeof payload.app_version === 'string' &&
    payload.app_version.length <= 64 &&
    Number.isInteger(payload.app_version_code) &&
    Number(payload.app_version_code) > 0 &&
    (payload.battery_percent === null ||
      (Number.isInteger(payload.battery_percent) &&
        Number(payload.battery_percent) >= 0 &&
        Number(payload.battery_percent) <= 100)) &&
    ['WIFI', 'CELLULAR', 'ETHERNET', 'VPN', 'NONE', 'UNKNOWN'].includes(
      String(payload.network_type)
    ) &&
    (payload.last_location_at === null ||
      (typeof payload.last_location_at === 'string' &&
        !Number.isNaN(Date.parse(payload.last_location_at)))) &&
    (payload.wifi_ssid === undefined || payload.wifi_ssid === null ||
      (typeof payload.wifi_ssid === 'string' && payload.wifi_ssid.length <= 32))
  )
}

function activeSessionKey(deviceId: string) {
  return `${ACTIVE_SESSION_PREFIX}${deviceId}`
}

function presenceKey(deviceId: string) {
  return `${PRESENCE_PREFIX}${deviceId}`
}

async function refreshSessionLease(connection: DeviceConnection): Promise<boolean> {
  const redis = getRedisClient()
  await ensureRedisConnected()
  const key = activeSessionKey(connection.deviceId)
  const current = await redis.get(key)
  if (current !== connection.sessionId) return false
  await redis.expire(key, env.get('WS_HEARTBEAT_TIMEOUT_SECONDS') * 2)
  return true
}

async function authenticateDevice(
  socket: WebSocket,
  firstMessage: JsonRecord,
  state: { value?: DeviceConnection },
  observedIp: string | null
) {
  const data = firstMessage.data
  if (
    !isRecord(data) ||
    typeof data.device_credential !== 'string' ||
    !/^[a-f0-9]{64}$/i.test(data.device_credential)
  ) {
    socket.close(4401, 'Authentication required')
    return
  }

  const deviceToken = data.device_credential
  const sessionGeneration = randomUUID()
  const connectionId = randomUUID()
  let opened: OpenSessionResult
  try {
    opened = await callLaravelGateway<OpenSessionResult>(
      '/api/internal/v1/gateway/sessions',
      'POST',
      deviceToken,
      { session_generation: sessionGeneration, connection_id: connectionId, observed_ip: observedIp }
    )
    if (!opened.device_id || !opened.device_session_id) throw new Error('invalid_session_response')
    await ensureRedisConnected()
    await getRedisClient().set(activeSessionKey(opened.device_id), opened.device_session_id, {
      EX: env.get('WS_HEARTBEAT_TIMEOUT_SECONDS') * 2,
    })
    if (socket.readyState !== WebSocket.OPEN) {
      await callLaravelGateway('/api/internal/v1/gateway/sessions/current', 'DELETE', deviceToken, {
        session_id: opened.device_session_id,
      })
      return
    }
  } catch (error) {
    const status = error instanceof GatewayUpstreamError ? error.status : undefined
    log('warn', 'Device WebSocket authentication failed.', {
      status,
      error_type: error instanceof Error ? error.name : 'unknown',
    })
    socket.close(
      status === 401 ? 4401 : 1013,
      status === 401 ? 'Authentication failed' : 'Gateway unavailable'
    )
    return
  }

  const connection: DeviceConnection = {
    socket,
    deviceId: opened.device_id,
    deviceToken,
    sessionId: opened.device_session_id,
    authenticatedAt: Date.now(),
    lastHeartbeatAt: null,
    preauthMessages: 0,
    acknowledgementQueue: Promise.resolve(),
    closed: false,
    observedIp,
  }
  const previous = connectionsByDevice.get(connection.deviceId)
  connectionsByDevice.set(connection.deviceId, connection)
  state.value = connection
  if (previous && previous !== connection && previous.socket.readyState === WebSocket.OPEN) {
    previous.socket.close(4409, 'Replaced by a newer device session')
  }

  send(socket, 'device.authenticated', {
    device_id: connection.deviceId,
    device_session_id: connection.sessionId,
    credential_epoch: opened.credential_epoch,
    capabilities: opened.capabilities,
    server_time: new Date().toISOString(),
  })
  log('info', 'Device WebSocket session authenticated.', {
    device_id: connection.deviceId,
    device_session_id: connection.sessionId,
  })
}

async function processHeartbeat(connection: DeviceConnection, payload: unknown) {
  if (!validHeartbeat(payload)) {
    send(connection.socket, 'protocol.error', {
      code: 'INVALID_HEARTBEAT',
      message: 'Format heartbeat tidak valid.',
    })
    return
  }

  try {
    if (!(await refreshSessionLease(connection))) {
      connection.socket.close(4409, 'Device session superseded')
      return
    }

    const heartbeatPayload = {
      request_id: payload.request_id,
      reported_at: payload.reported_at,
      android_version: payload.android_version,
      android_api: payload.android_api,
      app_version: payload.app_version,
      app_version_code: payload.app_version_code,
      battery_percent: payload.battery_percent,
      network_type: payload.network_type,
      wifi_ssid: payload.wifi_ssid ?? null,
      last_location_at: payload.last_location_at,
      capabilities: {
        camera_available: (payload.capabilities as JsonRecord).camera_available,
        front_camera_available: (payload.capabilities as JsonRecord).front_camera_available,
        back_camera_available: (payload.capabilities as JsonRecord).back_camera_available,
        location_available: (payload.capabilities as JsonRecord).location_available,
        managed_device: (payload.capabilities as JsonRecord).managed_device,
        device_owner: (payload.capabilities as JsonRecord).device_owner,
        foreground_service_available: (payload.capabilities as JsonRecord)
          .foreground_service_available,
        notification_permission: (payload.capabilities as JsonRecord).notification_permission,
      },
    }
    const heartbeat = await callLaravelGateway<HeartbeatResult>(
      '/api/internal/v1/gateway/heartbeat',
      'POST',
      connection.deviceToken,
      { session_id: connection.sessionId, ...heartbeatPayload }
    )
    if (heartbeat.accepted) {
      connection.lastHeartbeatAt = Date.now()
      await getRedisClient().set(
        presenceKey(connection.deviceId),
        JSON.stringify({
          device_id: connection.deviceId,
          device_session_id: connection.sessionId,
          last_heartbeat_at: heartbeat.server_received_at,
          status: heartbeat.status,
        }),
        { EX: env.get('WS_PRESENCE_TTL_SECONDS') }
      )
    }
    send(connection.socket, 'device.heartbeat.ack', heartbeat as unknown as JsonRecord)
  } catch (error) {
    const status = error instanceof GatewayUpstreamError ? error.status : undefined
    log('warn', 'Device heartbeat forwarding failed.', {
      device_id: connection.deviceId,
      device_session_id: connection.sessionId,
      status,
      error_type: error instanceof Error ? error.name : 'unknown',
    })
    send(connection.socket, 'protocol.error', {
      code: 'HEARTBEAT_REJECTED',
      message: 'Heartbeat belum diterima server.',
    })
    if (status === 401 || status === 409) connection.socket.close(4401, 'Device session rejected')
  }
}

function commandRetryDelay(attempt: number): number {
  const base = Math.min(60, 2 ** Math.min(Math.max(attempt, 1), 6))
  return base + randomInt(0, 4)
}

function scheduleCommandPoll(delayMs: number): void {
  if (!gatewayReady) return
  if (commandPollTimer) clearTimeout(commandPollTimer)
  commandPollTimer = setTimeout(() => void dispatchPendingCommands(), delayMs)
  commandPollTimer.unref()
}

function updateCommandPollBackoff(succeeded: boolean): number {
  if (succeeded) {
    commandPollBackoffMs = 2_000
    return commandPollBackoffMs
  }
  const cap = 60_000
  commandPollBackoffMs = Math.min(cap, commandPollBackoffMs * 2)
  const jitterMax = Math.max(1, Math.floor(commandPollBackoffMs / 4))
  return commandPollBackoffMs + randomInt(0, jitterMax + 1)
}

function validCommandAcknowledgement(payload: unknown): payload is JsonRecord {
  return (
    isRecord(payload) &&
    typeof payload.command_id === 'string' &&
    /^[0-9a-f-]{36}$/i.test(payload.command_id) &&
    ['DELIVERED', 'RECEIVED', 'EXECUTING', 'SUCCESS', 'FAILED'].includes(String(payload.status)) &&
    (payload.result_code === undefined ||
      (typeof payload.result_code === 'string' && /^[A-Z0-9_]{1,64}$/.test(payload.result_code))) &&
    (payload.result_message === undefined ||
      (typeof payload.result_message === 'string' && payload.result_message.length <= 500))
  )
}

async function processCommandAcknowledgement(connection: DeviceConnection, payload: unknown) {
  if (!validCommandAcknowledgement(payload)) {
    send(connection.socket, 'protocol.error', {
      code: 'INVALID_COMMAND_ACKNOWLEDGEMENT',
      message: 'Acknowledgment command tidak valid.',
    })
    return
  }
  try {
    const acknowledgement = await callLaravelGateway<JsonRecord>(
      '/api/internal/v1/gateway/commands/acknowledge',
      'POST',
      connection.deviceToken,
      {
        command_id: payload.command_id,
        status: payload.status,
        session_id: connection.sessionId,
        ...(payload.result_code ? { result_code: payload.result_code } : {}),
        ...(payload.result_message ? { result_message: payload.result_message } : {}),
      }
    )
    send(connection.socket, 'device.command.acknowledgement.accepted', acknowledgement)
  } catch (error) {
    const status = error instanceof GatewayUpstreamError ? error.status : undefined
    log('warn', 'Device command acknowledgement rejected by Laravel.', {
      device_id: connection.deviceId,
      device_session_id: connection.sessionId,
      command_id: payload.command_id,
      command_status: payload.status,
      upstream_status: status,
      error_type: error instanceof Error ? error.name : 'unknown',
    })
    send(connection.socket, 'protocol.error', {
      code: status === 409 ? 'COMMAND_STATE_REJECTED' : 'COMMAND_ACKNOWLEDGEMENT_FAILED',
      message:
        status === 409
          ? 'Status atau sesi command tidak sesuai.'
          : 'Server belum menerima acknowledgment.',
    })
    if (status === 401) connection.socket.close(4401, 'Device credential rejected')
  }
}

async function dispatchClaimedCommand(item: ClaimedCommand): Promise<void> {
  const connection = connectionsByDevice.get(item.device_id)
  if (!connection || connection.socket.readyState !== WebSocket.OPEN || connection.closed) {
    await callLaravelService(`/api/internal/v1/gateway/outbox/${item.outbox_id}/defer`, {
      claim_id: item.claim_id,
      retry_after_seconds: commandRetryDelay(item.attempt),
      reason: 'DEVICE_OFFLINE',
    })
    return
  }

  const sent = await callLaravelGateway<JsonRecord>(
    `/api/internal/v1/gateway/outbox/${item.outbox_id}/sent`,
    'POST',
    connection.deviceToken,
    { claim_id: item.claim_id, session_id: connection.sessionId }
  )
  if (sent.accepted !== true) throw new Error('Laravel did not accept the command dispatch')

  if (connection.socket.readyState !== WebSocket.OPEN || connection.closed) {
    log('warn', 'Device disconnected after the command dispatch lease was confirmed.', {
      device_id: item.device_id,
      command_id: item.command_id,
      device_session_id: connection.sessionId,
    })
    return
  }

  connection.socket.send(
    JSON.stringify({
      type: 'device.command.created',
      data: {
        command_id: item.command_id,
        device_id: item.device_id,
        device_session_id: connection.sessionId,
        command_type: item.command_type,
        sequence: item.sequence,
        payload: item.payload,
        expires_at: item.expires_at,
      },
    }),
    { compress: false },
    (error) => {
      if (error) {
        log('warn', 'WebSocket command frame could not be written; lease retry remains active.', {
          device_id: item.device_id,
          command_id: item.command_id,
          device_session_id: connection.sessionId,
          error_type: error.name,
        })
        return
      }
      log('info', 'Command frame sent to the authenticated device session.', {
        device_id: item.device_id,
        command_id: item.command_id,
        device_session_id: connection.sessionId,
        sequence: item.sequence,
      })
    }
  )
}

async function dispatchPendingCommands(): Promise<void> {
  if (commandPollRunning || !gatewayReady) return
  commandPollRunning = true
  let pollSucceeded = false
  try {
    const claim = await callLaravelService<{ items: ClaimedCommand[] }>(
      '/api/internal/v1/gateway/outbox/claim',
      { limit: 20 }
    )
    pollSucceeded = true
    for (const item of claim.items) {
      try {
        await dispatchClaimedCommand(item)
      } catch (error) {
        pollSucceeded = false
        log('warn', 'Command dispatch attempt failed; its PostgreSQL lease will recover.', {
          device_id: item.device_id,
          command_id: item.command_id,
          outbox_id: item.outbox_id,
          error_type: error instanceof Error ? error.name : 'unknown',
        })
      }
    }
  } catch (error) {
    log('warn', 'Command outbox poll failed.', {
      error_type: error instanceof Error ? error.name : 'unknown',
      upstream_status: error instanceof GatewayUpstreamError ? error.status : undefined,
    })
  } finally {
    commandPollRunning = false
    scheduleCommandPoll(updateCommandPollBackoff(pollSucceeded))
  }
}

async function closeDeviceSession(connection: DeviceConnection) {
  if (connection.closed) return
  connection.closed = true

  const current = connectionsByDevice.get(connection.deviceId)
  if (current === connection) connectionsByDevice.delete(connection.deviceId)
  try {
    await ensureRedisConnected()
    const redis = getRedisClient()
    const currentSessionId = await redis.get(activeSessionKey(connection.deviceId))
    if (currentSessionId === connection.sessionId) {
      await redis.del(activeSessionKey(connection.deviceId))
      const presence = await redis.get(presenceKey(connection.deviceId))
      if (presence) {
        const parsed = JSON.parse(presence) as { device_session_id?: string }
        if (parsed.device_session_id === connection.sessionId)
          await redis.del(presenceKey(connection.deviceId))
      }
    }
  } catch (error) {
    log('warn', 'Device presence cleanup failed.', {
      device_id: connection.deviceId,
      error_type: error instanceof Error ? error.name : 'unknown',
    })
  }

  try {
    await callLaravelGateway(
      '/api/internal/v1/gateway/sessions/current',
      'DELETE',
      connection.deviceToken,
      { session_id: connection.sessionId }
    )
  } catch (error) {
    log('warn', 'Device session close could not be persisted.', {
      device_id: connection.deviceId,
      device_session_id: connection.sessionId,
      error_type: error instanceof Error ? error.name : 'unknown',
    })
  }
  log('info', 'Device WebSocket session closed.', {
    device_id: connection.deviceId,
    device_session_id: connection.sessionId,
  })
}

function onConnection(socket: WebSocket, request: IncomingMessage) {
  const state: { value?: DeviceConnection } = {}
  let preauthMessages = 0
  let authTimeout: NodeJS.Timeout | undefined = setTimeout(() => {
    if (!state.value) socket.close(4401, 'Authentication timeout')
  }, env.get('WS_AUTH_TIMEOUT_MS'))
  authTimeout.unref()

  socket.on('message', (raw, isBinary) => {
    if (isBinary) {
      socket.close(1003, 'Text frames only')
      return
    }

    let message: unknown
    try {
      message = parseJson(raw)
    } catch {
      socket.close(1003, 'Invalid JSON frame')
      return
    }
    if (!isRecord(message) || typeof message.type !== 'string') {
      send(socket, 'protocol.error', { code: 'INVALID_EVENT', message: 'Event tidak valid.' })
      return
    }

    if (!state.value) {
      preauthMessages += 1
      if (preauthMessages > MAX_PREAUTH_MESSAGES || message.type !== 'device.authenticate') {
        socket.close(4401, 'Authentication required')
        return
      }
      if (authTimeout) clearTimeout(authTimeout)
      authTimeout = undefined
      void authenticateDevice(socket, message, state, cloudflareClientIp(request)).then(() => {
        if (state.value) {
          state.value.preauthMessages = preauthMessages
          state.value.lastHeartbeatAt = null
        }
      })
      return
    }

    if (message.type === 'device.heartbeat') {
      void processHeartbeat(state.value, message.data)
      return
    }
    if (message.type === 'device.command.acknowledgement') {
      const connection = state.value
      connection.acknowledgementQueue = connection.acknowledgementQueue.then(() =>
        processCommandAcknowledgement(connection, message.data)
      )
      return
    }
    send(socket, 'protocol.error', {
      code: 'UNSUPPORTED_EVENT',
      message: 'Event perangkat belum didukung.',
    })
  })

  socket.on('close', () => {
    if (authTimeout) clearTimeout(authTimeout)
    if (state.value) void closeDeviceSession(state.value)
  })
  socket.on('error', (error) => {
    log('warn', 'WebSocket transport error.', {
      device_id: state.value?.deviceId,
      error_type: error.name,
    })
  })
}

function applyKeepalive() {
  const timer = setInterval(
    () => {
      for (const connection of connectionsByDevice.values()) {
        if (connection.socket.readyState !== WebSocket.OPEN) continue
        const latestHeartbeat = connection.lastHeartbeatAt ?? connection.authenticatedAt
        const heartbeatExpired =
          Date.now() - latestHeartbeat > env.get('WS_HEARTBEAT_TIMEOUT_SECONDS') * 1_000
        if (heartbeatExpired) {
          connection.socket.close(4408, 'Heartbeat timeout')
          continue
        }
        connection.socket.ping()
      }
    },
    env.get('WS_HEARTBEAT_INTERVAL_SECONDS') * 1_000
  )
  timer.unref()
  return timer
}

export function attachRealtimeGateway(server: HttpServer): void {
  gatewayServer = new WebSocketServer({
    noServer: true,
    maxPayload: MAX_FRAME_BYTES,
    perMessageDeflate: false,
  })
  server.on('upgrade', (request, socket, head) => {
    const url = new URL(request.url ?? '/', 'http://localhost')
    const ip = cloudflareClientIp(request) ?? request.socket.remoteAddress ?? 'unknown'
    if (url.pathname !== env.get('WS_PATH') || !originAllowed(request) || !mayConnectFromIp(ip)) {
      socket.write('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n')
      socket.destroy()
      return
    }
    gatewayServer!.handleUpgrade(request, socket, head, (webSocket) => {
      gatewayServer!.emit('connection', webSocket, request)
    })
  })
  gatewayServer.on('connection', onConnection)
  gatewayReady = true
  keepaliveTimer = applyKeepalive()
  scheduleCommandPoll(500)
}

export function isRealtimeGatewayReady(): boolean {
  return gatewayReady
}

export async function closeRealtimeGateway(): Promise<void> {
  gatewayReady = false
  if (keepaliveTimer) clearInterval(keepaliveTimer)
  if (commandPollTimer) clearTimeout(commandPollTimer)
  for (const connection of connectionsByDevice.values())
    connection.socket.close(1001, 'Gateway shutting down')
  if (!gatewayServer) return
  await new Promise<void>((resolve) => gatewayServer!.close(() => resolve()))
  gatewayServer = undefined
}
