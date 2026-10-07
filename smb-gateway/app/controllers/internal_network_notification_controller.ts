import { timingSafeEqual } from 'node:crypto'
import type { HttpContext } from '@adonisjs/core/http'
import logger from '@adonisjs/core/services/logger'
import env from '#start/env'
import { sendNetworkViolationMessage } from '#services/telegram_bot_service'

export default class InternalNetworkNotificationController {
  async store({ request, response }: HttpContext) {
    const expected = env.get('GATEWAY_SHARED_SECRET') ?? ''
    const received = request.header('x-smb-internal-token') ?? ''
    if (!expected || expected.length < 32 || received.length !== expected.length ||
      !timingSafeEqual(Buffer.from(expected), Buffer.from(received))) {
      return response.status(401).json({ success: false, message: 'Unauthorized.' })
    }

    const body: unknown = request.body()
    if (!body || typeof body !== 'object' || Array.isArray(body)) {
      return response.status(422).json({ success: false, message: 'Invalid notification payload.' })
    }
    const data = body as Record<string, unknown>
    const recipients = data.recipients
    const message = data.message
    if (!Array.isArray(recipients) || recipients.length > 100 ||
      !recipients.every((id) => typeof id === 'string' && /^\d{1,20}$/.test(id)) ||
      typeof message !== 'string' || message.length < 1 || message.length > 3500) {
      return response.status(422).json({ success: false, message: 'Invalid notification recipients or message.' })
    }

    try {
      const result = await sendNetworkViolationMessage([...new Set(recipients)], message)
      return response.status(result.failed ? 207 : 200).json({ success: result.failed === 0, data: result })
    } catch (error) {
      logger.error({ error_type: error instanceof Error ? error.name : 'unknown' }, 'Network violation Telegram send failed')
      return response.status(503).json({ success: false, message: 'Telegram notification could not be sent.' })
    }
  }
}
