import { randomBytes, createHash } from 'node:crypto'
import env from '#start/env'
import { callLaravelService, GatewayUpstreamError } from '#services/laravel_gateway_client'
import { ensureRedisConnected, getRedisClient } from '#services/redis_client'

type BotHealth = {
  status: 'OK' | 'UNKNOWN' | 'DEGRADED' | 'FAIL'
  reason?: string
  checked_at?: string
}
type TelegramUser = { id: number }
type TelegramMessage = {
  message_id: number
  text?: string
  chat: { id: number; type: string }
  from?: TelegramUser
}
type TelegramCallback = { id: string; data?: string; from: TelegramUser; message?: TelegramMessage }
type TelegramUpdate = {
  update_id: number
  message?: TelegramMessage
  callback_query?: TelegramCallback
}
type DispatchInput = {
  telegram_id: number
  update_id: number
  action: 'devices' | 'status' | 'location' | 'lock' | 'unlock' | 'camera'
  device_id?: string
  camera_facing?: 'FRONT' | 'BACK'
  confirmed?: boolean
}
type DispatchResult = { reply_text: string; status?: string; command_id?: string }
type PendingConfirmation = Omit<DispatchInput, 'confirmed' | 'update_id'> & { telegram_id: number }
type ParsedCommand =
  | { kind: 'help' }
  | {
      kind: 'dispatch'
      action: DispatchInput['action']
      deviceId?: string
      cameraFacing?: 'FRONT' | 'BACK'
    }

const DEVICE_ID = /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
const CONFIRM_PREFIX = 'smb:telegram:confirm:'
const HELP =
  'Perintah SMB:\n/devices\n/status <DEVICE_UUID>\n/location <DEVICE_UUID>\n/lock <DEVICE_UUID>\n/unlock <DEVICE_UUID>\n/camera <DEVICE_UUID> [FRONT|BACK]'
const MAX_REPLY = 3900
let health: BotHealth = { status: 'UNKNOWN', reason: 'Telegram bot token belum dikonfigurasi.' }
let stopController: AbortController | undefined
let pollTask: Promise<void> | undefined

export function telegramBotHealth(): BotHealth {
  if (!pollTask && !stopController) startTelegramBot()
  if (
    health.checked_at &&
    Date.now() - Date.parse(health.checked_at) > 90_000 &&
    health.status === 'OK'
  ) {
    return {
      status: 'DEGRADED',
      reason: 'Tidak ada Telegram Bot API response lebih dari 90 detik.',
      checked_at: health.checked_at,
    }
  }
  return { ...health }
}

export function parseTelegramCommand(text: string): ParsedCommand {
  const [rawCommand = '', deviceId, facing] = text.trim().split(/\s+/)
  const command = rawCommand.split('@')[0].toLowerCase()
  if (command === '/start' || command === '/help') return { kind: 'help' }
  if (command === '/devices' && !deviceId) return { kind: 'dispatch', action: 'devices' }
  const action = (
    {
      '/status': 'status',
      '/location': 'location',
      '/lock': 'lock',
      '/unlock': 'unlock',
      '/camera': 'camera',
    } as const
  )[command]
  if (!action || !deviceId || !DEVICE_ID.test(deviceId)) return { kind: 'help' }
  if (action === 'camera' && facing && !['FRONT', 'BACK'].includes(facing.toUpperCase()))
    return { kind: 'help' }
  if (action !== 'camera' && facing) return { kind: 'help' }
  return {
    kind: 'dispatch',
    action,
    deviceId,
    ...(action === 'camera'
      ? { cameraFacing: (facing ?? 'BACK').toUpperCase() as 'FRONT' | 'BACK' }
      : {}),
  }
}

export function startTelegramBot(): void {
  if (pollTask) return
  const token = env.get('TELEGRAM_BOT_TOKEN')?.release()
  if (!token) {
    health = { status: 'UNKNOWN', reason: 'TELEGRAM_BOT_TOKEN belum dikonfigurasi.' }
    return
  }
  stopController = new AbortController()
  pollTask = pollTelegram(token, stopController.signal).finally(() => {
    pollTask = undefined
    stopController = undefined
  })
}

export async function stopTelegramBot(): Promise<void> {
  stopController?.abort()
  await pollTask?.catch(() => undefined)
}

async function pollTelegram(token: string, signal: AbortSignal): Promise<void> {
  let offset = 0
  let backoffMs = 1_000
  while (!signal.aborted) {
    try {
      const updates = await telegramCall<TelegramUpdate[]>(
        token,
        'getUpdates',
        {
          offset,
          timeout: 25,
          allowed_updates: ['message', 'callback_query'],
        },
        signal
      )
      health = { status: 'OK', checked_at: new Date().toISOString() }
      backoffMs = 1_000
      for (const update of updates) {
        if (signal.aborted) break
        try {
          await processUpdate(token, update)
          offset = Math.max(offset, update.update_id + 1)
        } catch (error) {
          health = {
            status: 'DEGRADED',
            reason: 'Update Telegram belum dapat diproses; bot akan mencoba ulang.',
            checked_at: new Date().toISOString(),
          }
          logBotError('telegram.update.failed', error)
          await abortableDelay(backoffMs + randomBytes(1)[0] * 10, signal)
          backoffMs = Math.min(backoffMs * 2, 60_000)
          break
        }
      }
    } catch (error) {
      if (signal.aborted) break
      health = {
        status: 'FAIL',
        reason: 'Telegram Bot API tidak dapat dijangkau.',
        checked_at: new Date().toISOString(),
      }
      logBotError('telegram.poll.failed', error)
      await abortableDelay(backoffMs + randomBytes(1)[0] * 10, signal)
      backoffMs = Math.min(backoffMs * 2, 60_000)
    }
  }
}

async function processUpdate(token: string, update: TelegramUpdate): Promise<void> {
  if (update.callback_query) {
    await processCallback(token, update.update_id, update.callback_query)
    return
  }
  const message = update.message
  if (
    !message?.from ||
    message.chat.type !== 'private' ||
    message.chat.id !== message.from.id ||
    !message.text
  )
    return
  const parsed = parseTelegramCommand(message.text)
  if (parsed.kind === 'help') {
    await sendMessage(
      token,
      message.chat.id,
      `Telegram ID: ${message.from.id}\nHubungi admin SMB untuk approval.\n\n${HELP}`
    )
    return
  }

  const input: DispatchInput = {
    telegram_id: message.from.id,
    update_id: update.update_id,
    action: parsed.action,
    ...(parsed.deviceId ? { device_id: parsed.deviceId } : {}),
    ...(parsed.cameraFacing ? { camera_facing: parsed.cameraFacing } : {}),
  }
  const result = await dispatch(input)
  if (result.reply_text.includes('harus dikonfirmasi')) {
    await sendConfirmation(token, message.chat.id, input)
  } else {
    await sendMessage(token, message.chat.id, result.reply_text)
  }
}

async function processCallback(
  token: string,
  updateId: number,
  callback: TelegramCallback
): Promise<void> {
  const chatId = callback.message?.chat.id
  if (!chatId || callback.message?.chat.type !== 'private' || chatId !== callback.from.id) {
    await answerCallback(token, callback.id, 'Konfirmasi hanya tersedia pada chat pribadi.')
    return
  }
  const data = callback.data ?? ''
  const [kind, nonce] = data.split(':')
  if (!nonce || !['c', 'x'].includes(kind)) {
    await answerCallback(token, callback.id, 'Konfirmasi tidak valid.')
    return
  }
  await ensureRedisConnected()
  const redis = getRedisClient()
  const stored = await redis.getDel(`${CONFIRM_PREFIX}${nonce}`)
  if (!stored) {
    await answerCallback(token, callback.id, 'Konfirmasi kedaluwarsa. Kirim ulang perintah.')
    return
  }
  const pending = JSON.parse(stored) as PendingConfirmation
  if (pending.telegram_id !== callback.from.id) {
    await answerCallback(token, callback.id, 'Konfirmasi ini milik akun Telegram lain.')
    return
  }
  if (kind === 'x') {
    await answerCallback(token, callback.id, 'Dibatalkan.')
    await sendMessage(token, chatId, 'Perintah dibatalkan; tidak ada command yang dikirim.')
    return
  }

  await answerCallback(token, callback.id, 'Memvalidasi izin dan mengirim ke server SMB…')
  try {
    const result = await dispatch({ ...pending, update_id: updateId, confirmed: true })
    await sendMessage(token, chatId, result.reply_text)
  } catch (error) {
    logBotError('telegram.confirmation.failed', error)
    await sendMessage(
      token,
      chatId,
      'Server SMB tidak dapat memproses permintaan. Tidak ada status sukses yang dilaporkan.'
    )
  }
}

async function dispatch(input: DispatchInput): Promise<DispatchResult> {
  return callLaravelService<DispatchResult>(
    '/api/internal/v1/telegram/dispatch',
    input as unknown as Record<string, unknown>
  )
}

async function sendConfirmation(
  token: string,
  chatId: number,
  input: DispatchInput
): Promise<void> {
  await ensureRedisConnected()
  const nonce = randomBytes(18).toString('hex')
  const pending: PendingConfirmation = {
    telegram_id: input.telegram_id,
    action: input.action,
    ...(input.device_id ? { device_id: input.device_id } : {}),
    ...(input.camera_facing ? { camera_facing: input.camera_facing } : {}),
  }
  await getRedisClient().set(`${CONFIRM_PREFIX}${nonce}`, JSON.stringify(pending), { EX: 60 })
  await telegramCall(token, 'sendMessage', {
    chat_id: chatId,
    text: `${input.action.toUpperCase()} pada perangkat ${input.device_id} akan dikirim melalui server SMB. Konfirmasi berlaku 60 detik.`,
    reply_markup: {
      inline_keyboard: [
        [
          { text: 'Konfirmasi', callback_data: `c:${nonce}` },
          { text: 'Batal', callback_data: `x:${nonce}` },
        ],
      ],
    },
  })
}

async function answerCallback(token: string, callbackId: string, text: string): Promise<void> {
  await telegramCall(token, 'answerCallbackQuery', { callback_query_id: callbackId, text })
}

async function sendMessage(token: string, chatId: number, text: string): Promise<void> {
  const message =
    text.length > MAX_REPLY ? `${text.slice(0, MAX_REPLY - 32)}\n…daftar dipotong.` : text
  await telegramCall(token, 'sendMessage', { chat_id: chatId, text: message })
}

export async function sendNetworkViolationMessage(
  recipients: string[],
  text: string
): Promise<{ sent: number; failed: number }> {
  const token = env.get('TELEGRAM_BOT_TOKEN')?.release()
  if (!token) throw new Error('Telegram bot token is not configured')
  const message = text.slice(0, MAX_REPLY)
  const results = await Promise.allSettled(
    recipients.map((chatId) => telegramCall(token, 'sendMessage', { chat_id: chatId, text: message }))
  )
  return {
    sent: results.filter((result) => result.status === 'fulfilled').length,
    failed: results.filter((result) => result.status === 'rejected').length,
  }
}

async function telegramCall<T = unknown>(
  token: string,
  method: string,
  payload: Record<string, unknown>,
  signal?: AbortSignal
): Promise<T> {
  const baseUrl = env.get('TELEGRAM_API_BASE_URL') ?? 'https://api.telegram.org'
  const response = await fetch(`${baseUrl.replace(/\/$/, '')}/bot${token}/${method}`, {
    method: 'POST',
    headers: { 'content-type': 'application/json', 'accept': 'application/json' },
    body: JSON.stringify(payload),
    signal: signal ?? AbortSignal.timeout(10_000),
  })
  const result = (await response.json().catch(() => null)) as {
    ok?: boolean
    result?: T
    description?: string
  } | null
  if (!response.ok || !result?.ok)
    throw new Error(`Telegram API ${method} returned ${response.status}`)
  return result.result as T
}

function logBotError(message: string, error: unknown): void {
  const status = error instanceof GatewayUpstreamError ? error.status : undefined
  process.stderr.write(
    `${JSON.stringify({
      timestamp: new Date().toISOString(),
      service: 'smb-gateway',
      level: 'error',
      message,
      upstream_status: status,
      error_type: error instanceof Error ? error.name : 'UnknownError',
      error_fingerprint: createHash('sha256')
        .update(error instanceof Error ? error.name : 'unknown')
        .digest('hex')
        .slice(0, 12),
    })}\n`
  )
}

function abortableDelay(milliseconds: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve) => {
    if (signal.aborted) return resolve()
    const timer = setTimeout(done, milliseconds)
    timer.unref()
    signal.addEventListener('abort', done, { once: true })
    function done() {
      clearTimeout(timer)
      signal.removeEventListener('abort', done)
      resolve()
    }
  })
}
