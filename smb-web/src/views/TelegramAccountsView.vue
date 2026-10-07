<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Check, MessageCircle, Plus, RefreshCw, ShieldOff, Trash2, UserRound } from '@lucide/vue'
import PageHeader from '../components/PageHeader.vue'
import { api, apiMessage, type ApiEnvelope, type Paginated } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import type { TelegramAccount, TelegramEligibleUser } from '../types/platform'

const auth = useAuthStore()
const accounts = ref<TelegramAccount[]>([])
const eligibleUsers = ref<TelegramEligibleUser[]>([])
const currentPage = ref(1)
const perPage = 25
const total = ref(0)
const lastPage = ref(1)
const activeFilter = ref('')
const eligibleQuery = ref('')
const form = ref({ user_id: '', telegram_id: '' })
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const canManage = computed(() => auth.can('telegram.manage'))

async function loadUsers() {
  const response = await api.get<ApiEnvelope<Paginated<TelegramEligibleUser>>>('/api/v1/telegram/accounts/eligible-users', { params: { per_page: 100, ...(eligibleQuery.value.trim() ? { q: eligibleQuery.value.trim() } : {}) } })
  eligibleUsers.value = response.data.data.items
  if (form.value.user_id && !eligibleUsers.value.some((user) => String(user.id) === form.value.user_id)) form.value.user_id = ''
}

async function loadAccounts() {
  const params: Record<string, number | boolean> = { page: currentPage.value, per_page: perPage }
  if (activeFilter.value !== '') params.is_active = activeFilter.value === 'active'
  const response = await api.get<ApiEnvelope<Paginated<TelegramAccount>>>('/api/v1/telegram/accounts', { params })
  accounts.value = response.data.data.items
  total.value = response.data.data.pagination.total
  lastPage.value = response.data.data.pagination.last_page
}

async function refresh() {
  loading.value = true
  error.value = ''
  try {
    await Promise.all([loadUsers(), loadAccounts()])
  } catch (requestError) {
    error.value = apiMessage(requestError)
  } finally {
    loading.value = false
  }
}

async function createAccount() {
  if (!canManage.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await api.post('/api/v1/telegram/accounts', { user_id: Number(form.value.user_id), telegram_id: form.value.telegram_id.trim() })
    form.value.telegram_id = ''
    activeFilter.value = 'pending'
    currentPage.value = 1
    notice.value = 'Pemetaan dibuat dalam status menunggu persetujuan. Aktifkan setelah identitas diperiksa.'
    await loadAccounts()
  } catch (requestError) {
    error.value = apiMessage(requestError)
  } finally {
    busy.value = false
  }
}

async function setActive(account: TelegramAccount, isActive: boolean) {
  if (!canManage.value) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await api.patch(`/api/v1/telegram/accounts/${account.id}`, { is_active: isActive })
    notice.value = isActive ? `Telegram ID ${account.telegram_id} disetujui.` : `Akses Telegram ${account.telegram_id} dinonaktifkan.`
    await loadAccounts()
  } catch (requestError) {
    error.value = apiMessage(requestError)
  } finally {
    busy.value = false
  }
}

async function revoke(account: TelegramAccount) {
  if (!canManage.value || !window.confirm(`Cabut pemetaan Telegram ID ${account.telegram_id} dari ${account.user.name}? Tindakan ini dicatat di audit.`)) return
  busy.value = true
  error.value = ''
  notice.value = ''
  try {
    await api.delete(`/api/v1/telegram/accounts/${account.id}`)
    notice.value = `Pemetaan Telegram ID ${account.telegram_id} dicabut.`
    await loadAccounts()
  } catch (requestError) {
    error.value = apiMessage(requestError)
  } finally {
    busy.value = false
  }
}

function formatDate(value: string | null) {
  if (!value) return 'Belum pernah terlihat'
  return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
}

onMounted(refresh)
</script>

<template>
  <PageHeader eyebrow="Keamanan akses" title="Akses Telegram" description="Kelola pemetaan identitas Telegram ke akun SMB. Pemetaan baru menunggu persetujuan; semua tindakan sensitif tetap melewati izin backend dan konfirmasi Telegram." />

  <div v-if="error" role="alert" class="mb-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ error }}</div>
  <div v-if="notice" role="status" class="mb-4 rounded-xl border border-teal/20 bg-teal/5 px-4 py-3 text-sm text-ink">{{ notice }}</div>

  <section v-if="canManage" class="mb-5 rounded-2xl border border-line bg-white p-5 shadow-card sm:p-6">
    <div class="flex items-center gap-3">
      <span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-ocean"><UserRound :size="19" /></span>
      <div><h2 class="font-semibold text-ink">Daftarkan identitas Telegram</h2><p class="text-xs text-muted">Minta pengguna mengirim /start ke bot dan verifikasi Telegram ID sebelum aktivasi.</p></div>
    </div>
    <form class="mt-5 grid gap-3 md:grid-cols-[1fr_1fr_auto]" @submit.prevent="createAccount">
      <label class="text-xs font-semibold text-ink">Cari pengguna SMB
        <span class="mt-1.5 flex gap-2"><input v-model.trim="eligibleQuery" maxlength="160" class="min-w-0 flex-1 rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-normal" placeholder="Nama atau user ID" @keyup.enter.prevent="loadUsers" /><button type="button" class="rounded-xl border border-line px-3 py-2 text-xs font-semibold" :disabled="loading" @click="loadUsers">Cari</button></span>
      </label>
      <label class="text-xs font-semibold text-ink">Akun SMB
        <select v-model="form.user_id" required class="mt-1.5 w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-normal">
          <option value="">Pilih pengguna sesuai cakupan akses</option>
          <option v-for="user in eligibleUsers" :key="user.id" :value="String(user.id)">{{ user.name }} · {{ user.username }}</option>
        </select>
      </label>
      <label class="text-xs font-semibold text-ink">Telegram ID
        <input v-model.trim="form.telegram_id" required inputmode="numeric" pattern="[0-9]{1,16}" maxlength="16" class="mt-1.5 w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-normal" placeholder="Contoh: 123456789" />
      </label>
      <button class="inline-flex items-center justify-center gap-2 self-end rounded-xl bg-navy px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="busy || loading || eligibleUsers.length === 0"><Plus :size="16" />{{ busy ? 'Menyimpan…' : 'Daftarkan' }}</button>
    </form>
    <p v-if="!eligibleUsers.length && !loading" class="mt-3 text-xs text-amber-800">Tidak ada akun aktif yang dapat dipetakan dalam cakupan izin Anda.</p>
  </section>

  <section class="overflow-hidden rounded-2xl border border-line bg-white shadow-card">
    <div class="flex flex-col gap-3 border-b border-line p-4 sm:flex-row sm:items-center sm:justify-between sm:px-5">
      <div><h2 class="font-semibold text-ink">Pemetaan Telegram</h2><p class="mt-0.5 text-xs text-muted">{{ total }} identitas dalam filter</p></div>
      <div class="flex flex-wrap items-center gap-2">
        <label class="text-xs text-muted">Status
          <select v-model="activeFilter" class="ml-2 rounded-lg border border-line bg-white px-3 py-2 text-xs text-ink" @change="currentPage = 1; loadAccounts()">
            <option value="">Semua</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option>
          </select>
        </label>
        <button class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-xs font-semibold text-ink disabled:opacity-50" :disabled="loading" @click="refresh"><RefreshCw :size="14" :class="loading ? 'animate-spin' : ''" />Perbarui</button>
      </div>
    </div>
    <div v-if="loading && !accounts.length" class="px-5 py-12 text-center text-sm text-muted">Memuat pemetaan dari API…</div>
    <div v-else-if="!accounts.length" class="px-5 py-12 text-center"><MessageCircle :size="24" class="mx-auto text-slate-300" /><p class="mt-3 text-sm font-semibold text-ink">Belum ada pemetaan</p><p class="mt-1 text-xs text-muted">Daftarkan Telegram ID setelah pengguna mengenalkan diri melalui /start.</p></div>
    <div v-else class="overflow-x-auto">
      <table class="w-full min-w-[760px] text-left">
        <thead class="bg-canvas text-[10px] font-semibold uppercase tracking-wide text-muted"><tr><th class="px-5 py-3">Pengguna SMB</th><th class="px-5 py-3">Telegram ID</th><th class="px-5 py-3">Status</th><th class="px-5 py-3">Aktivitas terakhir</th><th class="px-5 py-3">Disetujui oleh</th><th class="px-5 py-3 text-right">Tindakan</th></tr></thead>
        <tbody class="divide-y divide-line">
          <tr v-for="account in accounts" :key="account.id" class="align-top">
            <td class="px-5 py-4"><p class="text-sm font-semibold text-ink">{{ account.user.name }}</p><p class="mt-1 text-xs text-muted">{{ account.user.username }}</p></td>
            <td class="px-5 py-4 font-mono text-xs text-ink">{{ account.telegram_id }}</td>
            <td class="px-5 py-4"><span class="rounded-full px-2.5 py-1 text-[10px] font-semibold" :class="account.is_active ? 'bg-teal/10 text-teal' : 'bg-amber-50 text-amber-800'">{{ account.is_active ? 'AKTIF' : 'MENUNGGU / NONAKTIF' }}</span><p v-if="account.approved_at" class="mt-1.5 text-[10px] text-muted">{{ formatDate(account.approved_at) }}</p></td>
            <td class="px-5 py-4 text-xs text-muted">{{ formatDate(account.last_seen_at) }}</td>
            <td class="px-5 py-4 text-xs text-muted">{{ account.approver?.name ?? 'Belum disetujui' }}</td>
            <td class="px-5 py-4"><div class="flex justify-end gap-1.5">
              <button v-if="!account.is_active" class="inline-flex items-center gap-1.5 rounded-lg border border-teal/20 px-2.5 py-2 text-xs font-semibold text-teal disabled:opacity-50" :disabled="busy" @click="setActive(account, true)"><Check :size="14" />Setujui</button>
              <button v-else class="inline-flex items-center gap-1.5 rounded-lg border border-line px-2.5 py-2 text-xs font-semibold text-muted disabled:opacity-50" :disabled="busy" @click="setActive(account, false)"><ShieldOff :size="14" />Nonaktifkan</button>
              <button class="rounded-lg border border-rose/20 p-2 text-rose disabled:opacity-50" :aria-label="`Cabut pemetaan ${account.telegram_id}`" :disabled="busy" @click="revoke(account)"><Trash2 :size="14" /></button>
            </div></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="flex items-center justify-between border-t border-line px-5 py-3 text-xs text-muted"><span>Halaman {{ currentPage }} / {{ lastPage }}</span><div class="flex gap-2"><button class="rounded-md border border-line px-2.5 py-1.5 disabled:opacity-40" :disabled="currentPage <= 1 || loading" @click="currentPage--; loadAccounts()">Sebelumnya</button><button class="rounded-md border border-line px-2.5 py-1.5 disabled:opacity-40" :disabled="currentPage >= lastPage || loading" @click="currentPage++; loadAccounts()">Berikutnya</button></div></div>
  </section>
  <p class="mt-4 text-xs leading-5 text-muted">Menonaktifkan atau mencabut pemetaan mencegah Telegram mengakses SMB pada permintaan berikutnya. Backend tetap memeriksa izin dan cakupan lokasi; item menu saja bukan kontrol keamanan.</p>
</template>
