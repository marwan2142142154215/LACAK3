import { test } from '@japa/runner'
import { parseTelegramCommand } from '#services/telegram_bot_service'

const uuid = '01a11447-8857-73ae-95af-431ef125644c'

test.group('Telegram command parser', () => {
  test('parses dashboard-style read commands into server gateway actions', ({ assert }) => {
    assert.deepEqual(parseTelegramCommand('/devices'), { kind: 'dispatch', action: 'devices' })
    assert.deepEqual(parseTelegramCommand(`/status ${uuid}`), {
      kind: 'dispatch',
      action: 'status',
      deviceId: uuid,
    })
    assert.deepEqual(parseTelegramCommand(`/location ${uuid}`), {
      kind: 'dispatch',
      action: 'location',
      deviceId: uuid,
    })
  })

  test('parses sensitive actions while leaving confirmation to a server-mediated callback', ({
    assert,
  }) => {
    assert.deepEqual(parseTelegramCommand(`/lock ${uuid}`), {
      kind: 'dispatch',
      action: 'lock',
      deviceId: uuid,
    })
    assert.deepEqual(parseTelegramCommand(`/camera ${uuid} front`), {
      kind: 'dispatch',
      action: 'camera',
      deviceId: uuid,
      cameraFacing: 'FRONT',
    })
    assert.deepEqual(parseTelegramCommand(`/unlock ${uuid}`), {
      kind: 'dispatch',
      action: 'unlock',
      deviceId: uuid,
    })
  })

  test('rejects malformed device targets and command arguments', ({ assert }) => {
    assert.deepEqual(parseTelegramCommand('/status not-a-uuid'), { kind: 'help' })
    assert.deepEqual(parseTelegramCommand(`/camera ${uuid} SIDEWAYS`), { kind: 'help' })
    assert.deepEqual(parseTelegramCommand(`/lock ${uuid} now`), { kind: 'help' })
    assert.deepEqual(parseTelegramCommand('/devices extra'), { kind: 'help' })
  })
})
