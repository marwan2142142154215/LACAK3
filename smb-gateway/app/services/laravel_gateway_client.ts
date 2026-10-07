import { createHash, createHmac, randomUUID } from 'node:crypto'
import env from '#start/env'

type GatewayResponse<T> = {
  success: boolean
  message: string
  data?: T
}

export class GatewayUpstreamError extends Error {
  constructor(
    readonly status: number,
    message: string
  ) {
    super(message)
    this.name = 'GatewayUpstreamError'
  }
}

export function signGatewayRequest(
  method: string,
  path: string,
  bodyText: string,
  deviceToken: string,
  timestamp = Math.floor(Date.now() / 1_000).toString(),
  requestId = randomUUID(),
  secret = env.get('GATEWAY_SHARED_SECRET') ?? ''
) {
  const bodyHash = createHash('sha256').update(bodyText).digest('hex')
  const authorization = `Bearer ${deviceToken}`
  const canonical = [
    timestamp,
    requestId.toLowerCase(),
    method.toUpperCase(),
    path,
    bodyHash,
    createHash('sha256').update(authorization).digest('hex'),
  ].join('\n')
  if (secret.length < 32) throw new Error('Gateway service secret is not configured')

  return {
    requestId,
    headers: {
      'Authorization': authorization,
      'Content-Type': 'application/json',
      'X-SMB-Service': 'adonisjs',
      'X-SMB-Timestamp': timestamp,
      'X-SMB-Request-ID': requestId,
      'X-SMB-Body-SHA256': bodyHash,
      'X-SMB-Signature': `v1=${createHmac('sha256', secret).update(canonical).digest('hex')}`,
    },
  }
}

async function callLaravel<T>(
  path: string,
  method: 'POST' | 'DELETE',
  bearerCredential: string,
  body: Record<string, unknown>
): Promise<T> {
  const bodyText = JSON.stringify(body)
  const signature = signGatewayRequest(method, path, bodyText, bearerCredential)
  const baseUrl = env.get('LARAVEL_INTERNAL_URL').replace(/\/$/, '')
  const response = await fetch(`${baseUrl}${path}`, {
    method,
    headers: signature.headers,
    body: bodyText,
    signal: AbortSignal.timeout(5_000),
  })

  let result: GatewayResponse<T>
  try {
    result = (await response.json()) as GatewayResponse<T>
  } catch {
    throw new GatewayUpstreamError(response.status, 'Laravel returned an invalid gateway response')
  }

  if (!response.ok || !result.success || result.data === undefined) {
    throw new GatewayUpstreamError(
      response.status,
      result.message || 'Laravel gateway request failed'
    )
  }

  return result.data
}

export function callLaravelGateway<T>(
  path: string,
  method: 'POST' | 'DELETE',
  deviceToken: string,
  body: Record<string, unknown>
): Promise<T> {
  return callLaravel<T>(path, method, deviceToken, body)
}

export function callLaravelService<T>(path: string, body: Record<string, unknown>): Promise<T> {
  return callLaravel<T>(path, 'POST', 'smb-gateway-service', body)
}
