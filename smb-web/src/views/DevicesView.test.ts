import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { api } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import DevicesView from './DevicesView.vue'

vi.mock('../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  apiMessage: (error: unknown) => error instanceof Error ? error.message : 'Permintaan gagal.',
  idempotencyKey: () => 'test-key',
}))

describe('device registration codes', () => {
  beforeEach(() => vi.clearAllMocks())

  it('generates and revokes a real single-use code through Laravel', async () => {
    vi.mocked(api.get).mockImplementation(async (url) => {
      if (url === '/api/v1/sites') return { data: { data: { items: [{ id: 3, code: 'JKT', name: 'Jakarta', timezone: 'Asia/Jakarta', is_active: true }] } } } as never
      if (url === '/api/v1/teams') return { data: { data: { items: [{ id: 8, site_id: 3, name: 'Operasional', is_active: true }] } } } as never
      return { data: { data: { items: [], pagination: { total: 0, current_page: 1, last_page: 1, per_page: 25 } } } } as never
    })
    vi.mocked(api.post).mockImplementation(async (url) => {
      if (url === '/api/v1/device-registration-codes') {
        return { data: { success: true, data: { id: 'code-uuid', code: 'abcde-fghij', expires_at: '2026-10-07T01:15:00Z' } } } as never
      }
      return { data: { success: true, message: 'Kode registrasi dicabut.' } } as never
    })
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    const pinia = createPinia()
  useAuthStore(pinia).setUser({ id: 2, name: 'Admin', username: 'admin', email: 'admin@example.test', roles: ['ADMIN'], permissions: ['devices.view', 'devices.create'] })
    const router = createRouter({ history: createMemoryHistory(), routes: [] })
    const wrapper = mount(DevicesView, { global: { plugins: [pinia, router] } })
    await flushPromises()

    await wrapper.findAll('button').find((button) => button.text().includes('Kode registrasi'))!.trigger('click')
    const modal = wrapper.find('.fixed')
    const selects = modal.findAll('select')
    await selects[0].setValue('3')
    await selects[1].setValue('8')
    await modal.findAll('button').find((button) => button.text().includes('Buat kode'))!.trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/v1/device-registration-codes', { site_id: 3, team_id: 8 })
    expect(wrapper.text()).toContain('abcde-fghij')
    await modal.findAll('button').find((button) => button.text().includes('Cabut kode'))!.trigger('click')
    await flushPromises()

    expect(window.confirm).toHaveBeenCalled()
    expect(api.post).toHaveBeenLastCalledWith('/api/v1/device-registration-codes/code-uuid/revoke')
    expect(wrapper.text()).toContain('Kode registrasi telah dicabut di server.')
    expect(wrapper.text()).not.toContain('abcde-fghij')
  })
})
