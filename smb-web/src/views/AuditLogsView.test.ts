import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import AuditLogsView from './AuditLogsView.vue'

vi.mock('../lib/api', () => ({
  api: { get: vi.fn() },
  apiMessage: (error: unknown) => error instanceof Error ? error.message : 'Permintaan gagal.',
}))

describe('Audit log viewer', () => {
  beforeEach(() => vi.clearAllMocks())

  it('loads actual paginated audit records and applies server filters', async () => {
    vi.mocked(api.get).mockImplementation(async (url) => {
      if (url === '/api/v1/sites?per_page=100') return { data: { data: { items: [{ id: 4, name: 'Jakarta' }] } } } as never
      return { data: { success: true, data: { items: [{
        id: 81,
        log_name: 'device-commands',
        event: 'device.command.created',
        subject_type: 'device',
        subject_id: '123e4567-e89b-12d3-a456-426614174000',
        actor: { id: 2, name: 'Admin SMB' },
        properties: { command_type: 'LOCK' },
        created_at: '2026-10-07T01:00:00Z',
      }], pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25 } } } } as never
    })

    const wrapper = mount(AuditLogsView)
    await flushPromises()

    expect(wrapper.text()).toContain('device.command.created')
    expect(wrapper.text()).toContain('Admin SMB')
    expect(wrapper.text()).toContain('LOCK')
    const event = wrapper.find('input[placeholder="contoh: device.locked"]')
    await event.setValue('device.command.created')
    await wrapper.find('form').trigger('submit')
    await flushPromises()

    expect(api.get).toHaveBeenCalledWith('/api/v1/audit-logs', expect.objectContaining({
      params: expect.objectContaining({ event: 'device.command.created', page: 1, per_page: 25 }),
    }))
  })
})
