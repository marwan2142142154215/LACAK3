import axios from 'axios'

export interface ApiEnvelope<T> {
  success: boolean
  message: string
  data: T
}

export interface Paginated<T> {
  items: T[]
  pagination: {
    total: number
    current_page: number
    last_page: number
    per_page: number
  }
}

export const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000',
  headers: { Accept: 'application/json' },
  withCredentials: true,
  withXSRFToken: true,
  timeout: 20_000,
})

export function apiMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    return error.response?.data?.message ?? 'Server SMB tidak dapat menyelesaikan permintaan.'
  }
  return error instanceof Error ? error.message : 'Terjadi kesalahan yang tidak diketahui.'
}

export function idempotencyKey(): string {
  return crypto.randomUUID()
}
