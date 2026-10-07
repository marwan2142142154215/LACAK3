<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Laptop, RefreshCw, ShieldCheck, Smartphone, XCircle } from '@lucide/vue'
import dayjs from 'dayjs'
import 'dayjs/locale/id'
import PageHeader from '../components/PageHeader.vue'
import { api, apiMessage, type ApiEnvelope } from '../lib/api'
import type { DeviceSession } from '../types/platform'

dayjs.locale('id')
const sessions = ref<DeviceSession[]>([])
const loading = ref(true)
const error = ref('')
const notice = ref('')
const revoking = ref('')

async function loadSessions() {
  loading.value = true; error.value = ''
  try { const response = await api.get<ApiEnvelope<DeviceSession[]>>('/api/v1/auth/sessions'); sessions.value = response.data.data }
  catch (loadError) { error.value = apiMessage(loadError) } finally { loading.value = false }
}

async function revokeSession(session: DeviceSession) {
  if (session.is_current || !window.confirm(`Cabut sesi “${session.name}” (${session.type})? Sesi tersebut akan kehilangan akses.`)) return
  revoking.value = session.id; error.value = ''; notice.value = ''
  try { await api.delete(`/api/v1/auth/sessions/${encodeURIComponent(session.id)}`); notice.value = 'Sesi berhasil dicabut.'; await loadSessions() }
  catch (requestError) { error.value = apiMessage(requestError) } finally { revoking.value = '' }
}

onMounted(loadSessions)
</script>

<template>
  <PageHeader eyebrow="Keamanan" title="Sesi akun" description="Daftar sesi berasal dari Sanctum dan Laravel. Cabut sesi yang tidak lagi dikenali.">
    <button class="inline-flex items-center gap-2 rounded-xl border border-line bg-white px-4 py-2.5 text-sm font-semibold text-ink" :disabled="loading" @click="loadSessions"><RefreshCw :size="15" :class="loading ? 'animate-spin' : ''" /> Perbarui</button>
  </PageHeader>
  <div v-if="error" role="alert" class="mb-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ error }}</div>
  <div v-if="notice" role="status" class="mb-4 rounded-xl border border-teal/20 bg-teal/5 px-4 py-3 text-sm text-ink">{{ notice }}</div>
  <section class="overflow-hidden rounded-2xl border border-line bg-white shadow-card">
    <div v-if="loading" class="px-5 py-14 text-center text-sm text-muted">Memuat sesi dari server…</div>
    <div v-else-if="!sessions.length" class="px-5 py-14 text-center text-sm text-muted">Tidak ada sesi aktif yang dikembalikan server.</div>
    <div v-else class="divide-y divide-line">
      <article v-for="session in sessions" :key="session.id" class="flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6">
        <div class="flex min-w-0 items-center gap-3"><div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-canvas text-ocean"><component :is="session.type === 'android' ? Smartphone : Laptop" :size="18" /></div><div class="min-w-0"><div class="flex flex-wrap items-center gap-2"><p class="truncate text-sm font-semibold text-ink">{{ session.name }}</p><span v-if="session.is_current" class="rounded-md bg-teal/10 px-2 py-0.5 text-[10px] font-semibold text-teal">Sesi ini</span></div><p class="mt-1 truncate text-xs text-muted">{{ session.type }} · {{ session.ip_address ?? 'Alamat IP tidak tersedia' }} · aktivitas {{ session.last_activity_at ? dayjs(session.last_activity_at).format('D MMM YYYY, HH:mm:ss') : 'tidak diketahui' }}</p><p v-if="session.user_agent" class="mt-1 truncate text-[10px] text-slate-600">{{ session.user_agent }}</p><p v-if="session.expires_at" class="mt-1 text-[10px] text-muted">Token kadaluarsa {{ dayjs(session.expires_at).format('D MMM YYYY, HH:mm') }}</p></div></div>
        <button v-if="!session.is_current" class="inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose hover:bg-rose-50 disabled:opacity-50" :disabled="!!revoking" @click="revokeSession(session)"><XCircle :size="14" />{{ revoking === session.id ? 'Mencabut…' : 'Cabut sesi' }}</button>
      </article>
    </div>
    <div class="flex items-center gap-2 border-t border-line bg-canvas px-5 py-3 text-[11px] text-muted"><ShieldCheck :size="14" class="text-teal" /> Session expiration dan pencabutan diverifikasi pada backend.</div>
  </section>
</template>
