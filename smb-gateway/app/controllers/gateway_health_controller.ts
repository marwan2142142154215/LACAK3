import type { HttpContext } from '@adonisjs/core/http'
import env from '#start/env'
import { pingRedis } from '#services/redis_client'
import { isRealtimeGatewayReady } from '#services/realtime_gateway'
import { telegramBotHealth } from '#services/telegram_bot_service'

type HealthStatus = 'OK' | 'FAIL' | 'UNKNOWN' | 'DEGRADED'

export default class GatewayHealthController {
  async show({ response }: HttpContext) {
    const checks: Record<string, { status: HealthStatus; reason?: string }> = {
      'AdonisJS': { status: 'OK' },
      'WebSocket': { status: isRealtimeGatewayReady() ? 'OK' : 'FAIL' },
      'Telegram': telegramBotHealth(),
      'Laravel service authentication': {
        status: (env.get('GATEWAY_SHARED_SECRET') ?? '').length >= 32 ? 'OK' : 'FAIL',
        ...((env.get('GATEWAY_SHARED_SECRET') ?? '').length >= 32
          ? {}
          : { reason: 'Gateway service secret is missing or too short.' }),
      },
    }

    try {
      await pingRedis()
      checks['Gateway Redis'] = { status: 'OK' }
    } catch {
      checks['Gateway Redis'] = { status: 'FAIL', reason: 'Redis connection failed.' }
    }

    checks.Laravel = {
      status: 'UNKNOWN',
      reason:
        'Laravel reports this gateway probe from its own health endpoint to avoid a circular check.',
    }

    checks['Cloudflare Tunnel'] = {
      status: 'UNKNOWN',
      reason: 'Tunnel probe is owned by the server launcher.',
    }
    const coreServices = Object.entries(checks).filter(([name]) => name !== 'Telegram')
    const coreFailure = coreServices.some(([, check]) => check.status === 'FAIL')
    const hasFailure = Object.values(checks).some((check) => check.status === 'FAIL')
    const degraded = Object.values(checks).some((check) =>
      ['UNKNOWN', 'DEGRADED'].includes(check.status)
    )
    const status = coreFailure ? 'FAIL' : hasFailure || degraded ? 'DEGRADED' : 'READY'

    return response.status(coreFailure ? 503 : 200).json({
      success: !hasFailure && !degraded,
      message:
        hasFailure || degraded
          ? 'Satu atau lebih dependency gateway tidak sehat.'
          : 'Pemeriksaan gateway selesai.',
      data: {
        status,
        checked_at: new Date().toISOString(),
        services: checks,
      },
    })
  }
}
