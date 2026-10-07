import { defineStore } from 'pinia'
import { api, apiMessage } from '../lib/api'
import type { ApiEnvelope } from '../lib/api'
import type { UserSummary } from '../types/platform'

interface AuthState {
  user: UserSummary | null
  bootstrapped: boolean
  error: string | null
}

export const useAuthStore = defineStore('auth', {
  state: (): AuthState => ({ user: null, bootstrapped: false, error: null }),
  getters: {
    can: (state) => (permission: string) => state.user?.permissions.includes(permission) ?? false,
    isAdmin: (state) => state.user?.roles.some((role) => ['SUPER_ADMIN', 'ADMIN'].includes(role)) ?? false,
  },
  actions: {
    setUser(user: UserSummary) {
      this.user = user
      this.bootstrapped = true
      this.error = null
    },
    async bootstrap() {
      if (this.bootstrapped) return
      try {
        const response = await api.get<ApiEnvelope<UserSummary>>('/api/v1/auth/me')
        this.user = response.data.data
      } catch (error) {
        this.user = null
        if (error && typeof error === 'object' && 'response' in error && (error as { response?: { status?: number } }).response?.status !== 401) {
          this.error = apiMessage(error)
        }
      } finally {
        this.bootstrapped = true
      }
    },
    async logout() {
      try {
        await api.post('/api/v1/auth/logout')
      } finally {
        this.user = null
        this.bootstrapped = true
      }
    },
  },
})
