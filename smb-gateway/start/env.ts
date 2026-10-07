import { Env } from '@adonisjs/core/env'

export default await Env.create(new URL('../', import.meta.url), {
  NODE_ENV: Env.schema.enum(['development', 'production', 'test'] as const),
  PORT: Env.schema.number(),
  HOST: Env.schema.string({ format: 'host' }),
  LOG_LEVEL: Env.schema.string(),
  APP_NAME: Env.schema.string(),
  APP_KEY: Env.schema.secret(),
  APP_URL: Env.schema.string({ format: 'url', tld: false }),
  LARAVEL_INTERNAL_URL: Env.schema.string(),
  REDIS_HOST: Env.schema.string(),
  REDIS_PORT: Env.schema.number(),
  REDIS_PASSWORD: Env.schema.secret.optional(),
  GATEWAY_SHARED_SECRET: Env.schema.string.optional(),
  TELEGRAM_BOT_TOKEN: Env.schema.secret.optional(),
  TELEGRAM_API_BASE_URL: Env.schema.string.optional(),
  WS_PATH: Env.schema.string(),
  WS_ALLOWED_ORIGINS: Env.schema.string.optional(),
  WS_AUTH_TIMEOUT_MS: Env.schema.number(),
  WS_HEARTBEAT_INTERVAL_SECONDS: Env.schema.number(),
  WS_HEARTBEAT_TIMEOUT_SECONDS: Env.schema.number(),
  WS_PRESENCE_TTL_SECONDS: Env.schema.number(),
})
