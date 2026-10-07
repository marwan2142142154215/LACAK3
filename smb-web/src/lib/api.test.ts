import { describe, expect, it } from 'vitest'
import { apiMessage, idempotencyKey } from './api'

describe('API helpers', () => {
  it('creates a UUID idempotency key for each command request', () => {
    expect(idempotencyKey()).toMatch(/^[0-9a-f-]{36}$/)
    expect(idempotencyKey()).not.toBe(idempotencyKey())
  })

  it('returns a clear error for unknown failures', () => {
    expect(apiMessage(new Error('Gateway timeout'))).toBe('Gateway timeout')
  })
})
