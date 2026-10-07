import { createClient } from 'redis'
import env from '#start/env'

type RedisClient = ReturnType<typeof createClient>

let client: RedisClient | undefined
let connecting: Promise<unknown> | undefined

export function getRedisClient(): RedisClient {
  if (client) return client

  client = createClient({
    password: env.get('REDIS_PASSWORD')?.release(),
    socket: {
      host: env.get('REDIS_HOST'),
      port: env.get('REDIS_PORT'),
      connectTimeout: 2_000,
      reconnectStrategy: (retries) => Math.min(retries * 250, 3_000),
    },
  })
  client.on('error', (error) => {
    process.stderr.write(
      `${JSON.stringify({
        timestamp: new Date().toISOString(),
        service: 'smb-gateway',
        level: 'error',
        message: 'Redis connection error',
        error_type: error.name,
      })}\n`
    )
  })

  return client
}

export async function ensureRedisConnected(timeoutMs = 3_000): Promise<void> {
  const redis = getRedisClient()
  if (redis.isReady) return

  if (!redis.isOpen) {
    connecting ??= redis.connect().finally(() => {
      connecting = undefined
    })
  } else if (!connecting) {
    throw new Error('Redis client is not ready')
  }

  let timer: NodeJS.Timeout | undefined
  try {
    await Promise.race([
      connecting,
      new Promise<never>((_, reject) => {
        timer = setTimeout(() => reject(new Error('Redis connection timed out')), timeoutMs)
        timer.unref()
      }),
    ])
  } finally {
    if (timer) clearTimeout(timer)
  }
}

export async function pingRedis(): Promise<string> {
  await ensureRedisConnected()
  return getRedisClient().ping()
}
