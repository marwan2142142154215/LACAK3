import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import TelegramAccountsView from './TelegramAccountsView.vue'

vi.mock('../lib/api', () => ({
  api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
  apiMessage: (error: unknown) => error instanceof Error ? error.message : 'Permintaan gagal.',
}))

const pendingAccount = {
  id: 4,
  telegram_id: '123456789',
  is_active: false,
  approved_at: null,
  last_seen_at: null,
  created_at: '2026-10-07T00:00:00Z',
  user: { id: 7, name: 'Operator SMB', username: 'operator', email: 'operator@example.test' },
  approver: null,
}
const activeAccount = { ...pendingAccount, is_active: true, approved_at: '2026-10-07T01:00:00Z', approver: { id: 2, name: 'Admin SMB' } }

function listResponse(account: typeof pendingAccount | typeof activeAccount) {
  return { data: { success: true, data: { items: [account], pagination: { total: 1, current_page: 1, last_page: 1, per_page: 25 } } } }
}

describe('Telegram account administration', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('loads real mapping data and sends approval through the API', async () => {
    let accountListCall = 0
    vi.mocked(api.get).mockImplementation(async (url) => {
      if (url === '/api/v1/telegram/accounts/eligible-users') {
        return { data: { data: { items: [{ id: 7, name: 'Operator SMB', username: 'operator', email: 'operator@example.test' }] } } } as never
      }
      accountListCall += 1
      return listResponse(accountListCall === 1 ? pendingAccount : activeAccount) as never
    })
    vi.mocked(api.patch).mockResolvedValue({ data: { success: true } } as never)

    const pinia = createPinia()
    useAuthStore(pinia).setUser({
      id: 2,
      name: 'Admin SMB',
      username: 'admin',
      email: 'admin@example.test',
      roles: ['ADMIN'],
      permissions: ['telegram.manage'],
    })
    const wrapper = mount(TelegramAccountsView, { global: { plugins: [pinia] } })
    await flushPromises()

    expect(wrapper.text()).toContain('123456789')
    expect(wrapper.text()).toContain('Operator SMB')
    const approveButton = wrapper.findAll('button').find((button) => button.text().includes('Setujui'))
    expect(approveButton).toBeDefined()
    await approveButton!.trigger('click')
    await flushPromises()

    expect(api.patch).toHaveBeenCalledWith('/api/v1/telegram/accounts/4', { is_active: true })
    expect(wrapper.text()).toContain('AKTIF')
    expect(wrapper.text()).toContain('Telegram ID 123456789 disetujui.')
  })
})
