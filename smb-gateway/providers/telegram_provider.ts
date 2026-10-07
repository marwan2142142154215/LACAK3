import type { ApplicationService } from '@adonisjs/core/types'
import { startTelegramBot, stopTelegramBot } from '#services/telegram_bot_service'

export default class TelegramProvider {
  constructor(private readonly app: ApplicationService) {}

  async ready() {
    startTelegramBot()
    this.app.terminating(() => stopTelegramBot())
  }
}
