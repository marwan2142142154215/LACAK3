import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import DownloadsView from './DownloadsView.vue'

vi.mock('../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn() },
  apiMessage: (error: unknown) => error instanceof Error ? error.message : 'Permintaan gagal.',
}))

describe('site-bound SMB Lacak downloads', () => {
  beforeEach(() => vi.clearAllMocks())

  it('creates a server registration code for the selected site/team before enabling the tracker APK', async () => {
    vi.mocked(api.get).mockImplementation(async (url) => {
      if (url === '/api/v1/releases') {
        return { data: { data: [{ id: 'tracker-release', app_name: 'SMB-Lacak', version: '1.0.0', version_code: 1, minimum_android_api: 26, released_at: '2026-10-07T00:00:00Z', checksum_sha256: 'a'.repeat(64) }] } } as never
      }
      if (url === '/api/v1/sites') {
        return { data: { data: { items: [{ id: 3, code: 'JKT', name: 'Jakarta', timezone: 'Asia/Jakarta', is_active: true }] } } } as never
      }
      return { data: { data: { items: [{ id: 8, site_id: 3, name: 'Operasional', is_active: true }] } } } as never
    })
    vi.mocked(api.post).mockResolvedValue({
      data: { success: true, data: { id: 'registration-uuid', code: 'abcde-fghij', site_id: 3, team_id: 8, expires_at: new Date(Date.now() + 10 * 60_000).toISOString(), single_use: true } },
    } as never)

    const pinia = createPinia()
    useAuthStore(pinia).setUser({ id: 2, name: 'Admin', username: 'admin', email: null, roles: ['ADMIN'], permissions: ['devices.view', 'devices.create', 'sites.view'] })
    const wrapper = mount(DownloadsView, { global: { plugins: [pinia] } })
    await flushPromises()

    const selects = wrapper.findAll('select')
    await selects[0].setValue('3')
    await selects[1].setValue('8')
    await wrapper.findAll('button').find((button) => button.text().includes('Buat kode dan sesi registrasi'))!.trigger('click')
    await flushPromises()

    expect(api.post).toHaveBeenCalledWith('/api/v1/device-registration-codes', { site_id: 3, team_id: 8 })
    expect(wrapper.text()).toContain('abcde-fghij')
    expect(wrapper.text()).toContain('Jakarta')
    expect(wrapper.text()).toContain('Operasional')
    expect(wrapper.text()).toContain('UNUSED')
    expect(wrapper.findAll('button').find((button) => button.text().includes('Unduh'))!.attributes('disabled')).toBeUndefined()
  })

  it('does not present an empty artifact catalog as a downloadable package', async () => {
    vi.mocked(api.get).mockResolvedValue({ data: { data: [] } } as never)
    const pinia = createPinia()
    useAuthStore(pinia).setUser({ id: 2, name: 'Viewer', username: 'viewer', email: null, roles: ['VIEWER'], permissions: ['devices.view'] })
    const wrapper = mount(DownloadsView, { global: { plugins: [pinia] } })
    await flushPromises()

    expect(wrapper.text()).toContain('Belum ada file rilis yang dipublikasikan')
    expect(wrapper.findAll('button').some((button) => button.text().includes('Unduh'))).toBe(false)
  })
})
