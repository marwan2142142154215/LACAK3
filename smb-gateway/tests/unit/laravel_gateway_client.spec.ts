import { createHash, createHmac } from 'node:crypto'
import { test } from '@japa/runner'
import { signGatewayRequest } from '#services/laravel_gateway_client'

test.group('Laravel gateway service signatures', () => {
  test('signs the method, request target, body and device bearer credential', ({ assert }) => {
    const testSecret = 'unit-test-secret-with-more-than-32-bytes'
    const body = '{"session_generation":"2d6d83ae-9be5-4318-8858-55b2ed0c640c"}'
    const token = 'a'.repeat(64)
    const timestamp = '1791360000'
    const requestId = '8726bd38-d090-44d3-a581-337ce797a3db'
    const signed = signGatewayRequest(
      'POST',
      '/api/internal/v1/gateway/sessions',
      body,
      token,
      timestamp,
      requestId,
      testSecret
    )
    const bodyHash = createHash('sha256').update(body).digest('hex')
    const authorization = `Bearer ${token}`
    const canonical = [
      timestamp,
      requestId,
      'POST',
      '/api/internal/v1/gateway/sessions',
      bodyHash,
      createHash('sha256').update(authorization).digest('hex'),
    ].join('\n')

    assert.equal(signed.requestId, requestId)
    assert.equal(signed.headers.Authorization, authorization)
    assert.equal(signed.headers['X-SMB-Body-SHA256'], bodyHash)
    assert.equal(
      signed.headers['X-SMB-Signature'],
      `v1=${createHmac('sha256', testSecret).update(canonical).digest('hex')}`
    )
  })

  test('signs gateway service outbox requests with the shared HMAC identity', ({ assert }) => {
    const testSecret = 'unit-test-secret-with-more-than-32-bytes'
    const body = '{"limit":20}'
    const bearerIdentity = 'smb-gateway-service'
    const timestamp = '1791360000'
    const requestId = 'b8b1ef22-6ce8-4bb2-8454-97eaf54fb909'
    const path = '/api/internal/v1/gateway/outbox/claim'
    const signed = signGatewayRequest(
      'POST',
      path,
      body,
      bearerIdentity,
      timestamp,
      requestId,
      testSecret
    )
    const authorization = `Bearer ${bearerIdentity}`
    const canonical = [
      timestamp,
      requestId,
      'POST',
      path,
      createHash('sha256').update(body).digest('hex'),
      createHash('sha256').update(authorization).digest('hex'),
    ].join('\n')

    assert.equal(signed.headers.Authorization, authorization)
    assert.equal(
      signed.headers['X-SMB-Signature'],
      `v1=${createHmac('sha256', testSecret).update(canonical).digest('hex')}`
    )
  })
})
