<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ClipboardList, RefreshCw, Search } from '@lucide/vue'
import dayjs from 'dayjs'
import 'dayjs/locale/id'
import PageHeader from '../components/PageHeader.vue'
import { api, apiMessage, type ApiEnvelope, type Paginated } from '../lib/api'
import type { AuditLogEntry, Site } from '../types/platform'

dayjs.locale('id')
const entries = ref<AuditLogEntry[]>([])
const sites = ref<Site[]>([])
const loading = ref(true)
const error = ref('')
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const filter = ref({ site_id: '', device_id: '', event: '', from: '', to: '' })

async function loadAudit() {
  loading.value = true
  error.value = ''
  const fromTime = filter.value.from ? new Date(filter.value.from).getTime() : null
  const toTime = filter.value.to ? new Date(filter.value.to).getTime() : null
  if ((fromTime !== null && Number.isNaN(fromTime)) || (toTime !== null && Number.isNaN(toTime))) {
    error.value = 'Format tanggal tidak valid.'
    loading.value = false
    return
  }
  if (fromTime !== null && toTime !== null && fromTime > toTime) {
    error.value = 'Tanggal awal harus sebelum tanggal akhir.'
    loading.value = false
    return
  }
  const params: Record<string, string | number> = { page: page.value, per_page: 25 }
  if (filter.value.site_id) params.site_id = filter.value.site_id
  if (filter.value.device_id.trim()) params.device_id = filter.value.device_id.trim()
  if (filter.value.event.trim()) params.event = filter.value.event.trim()
  if (fromTime !== null) params.from = new Date(fromTime).toISOString()
  if (toTime !== null) params.to = new Date(toTime).toISOString()
  try {
    const response = await api.get<ApiEnvelope<Paginated<AuditLogEntry>>>('/api/v1/audit-logs', { params })
    entries.value = response.data.data.items
    total.value = response.data.data.pagination.total
    lastPage.value = response.data.data.pagination.last_page
  } catch (requestError) {
    error.value = apiMessage(requestError)
    entries.value = []
  } finally {
    loading.value = false
  }
}

async function loadSites() {
  try {
    const response = await api.get<ApiEnvelope<Paginated<Site>>>('/api/v1/sites?per_page=100')
    sites.value = response.data.data.items
  } catch {
    // Site filtering is optional; audit access remains available without it.
  }
}

function applyFilters() { page.value = 1; void loadAudit() }

onMounted(() => { void loadSites(); void loadAudit() })
</script>

<template>
  <PageHeader eyebrow="Keamanan & kepatuhan" title="Audit aktivitas" description="Aktivitas diambil dari audit log server, dibatasi sesuai site yang dapat Anda akses." >
    <button class="inline-flex items-center gap-2 rounded-xl border border-line bg-white px-4 py-2.5 text-sm font-semibold text-ink" :disabled="loading" @click="loadAudit"><RefreshCw :size="15" :class="loading ? 'animate-spin' : ''" /> Perbarui</button>
  </PageHeader>

  <form class="mb-5 grid gap-3 rounded-2xl border border-line bg-white p-4 shadow-card md:grid-cols-2 xl:grid-cols-6" @submit.prevent="applyFilters">
    <label class="text-xs font-medium text-muted">Site
      <select v-model="filter.site_id" class="mt-1.5 h-10 w-full rounded-lg border border-line bg-white px-3 text-sm text-ink"><option value="">Semua site yang diizinkan</option><option v-for="site in sites" :key="site.id" :value="String(site.id)">{{ site.name }}</option></select>
    </label>
    <label class="text-xs font-medium text-muted">Device ID
      <input v-model="filter.device_id" placeholder="UUID perangkat" class="mt-1.5 h-10 w-full rounded-lg border border-line px-3 text-sm text-ink placeholder:text-slate-600">
    </label>
    <label class="text-xs font-medium text-muted">Event
      <input v-model="filter.event" placeholder="contoh: device.locked" class="mt-1.5 h-10 w-full rounded-lg border border-line px-3 text-sm text-ink placeholder:text-slate-600">
    </label>
    <label class="text-xs font-medium text-muted">Dari
      <input v-model="filter.from" type="datetime-local" class="mt-1.5 h-10 w-full rounded-lg border border-line px-2 text-xs text-ink">
    </label>
    <label class="text-xs font-medium text-muted">Sampai
      <input v-model="filter.to" type="datetime-local" class="mt-1.5 h-10 w-full rounded-lg border border-line px-2 text-xs text-ink">
    </label>
    <button class="mt-auto inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-navy px-3 text-sm font-semibold text-white hover:bg-navy/90"><Search :size="15" /> Terapkan</button>
  </form>

  <div v-if="error" role="alert" class="mb-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ error }}</div>
  <section class="overflow-hidden rounded-2xl border border-line bg-white shadow-card">
    <div class="flex items-center justify-between border-b border-line px-5 py-4"><div class="flex items-center gap-2 text-sm font-semibold text-ink"><ClipboardList :size="17" class="text-teal" /> Catatan audit</div><p class="text-xs text-muted">{{ total }} catatan</p></div>
    <div v-if="loading" class="px-5 py-14 text-center text-sm text-muted">Memuat audit log dari server…</div>
    <div v-else-if="!entries.length" class="px-5 py-14 text-center text-sm text-muted">Belum ada catatan untuk filter ini.</div>
    <div v-else class="divide-y divide-line">
      <article v-for="entry in entries" :key="entry.id" class="grid gap-2 px-5 py-4 md:grid-cols-[minmax(0,1fr)_220px] md:items-start md:px-6">
        <div class="min-w-0"><p class="break-all text-sm font-semibold text-ink">{{ entry.event }}</p><p class="mt-1 text-xs text-muted">{{ entry.subject_type ?? 'tanpa objek' }}<span v-if="entry.subject_id"> · {{ entry.subject_id }}</span></p><p class="mt-1 text-xs text-muted">Oleh {{ entry.actor?.name ?? 'Sistem' }}<span v-if="entry.log_name"> · {{ entry.log_name }}</span></p><pre v-if="Object.keys(entry.properties ?? {}).length" class="mt-2 overflow-x-auto rounded-lg bg-canvas p-3 text-[11px] text-muted">{{ JSON.stringify(entry.properties, null, 2) }}</pre></div>
        <time class="text-xs text-muted md:text-right">{{ entry.created_at ? dayjs(entry.created_at).format('D MMM YYYY, HH:mm:ss') : 'Waktu tidak tersedia' }}</time>
      </article>
    </div>
    <div class="flex items-center justify-between border-t border-line bg-canvas px-5 py-3 text-xs text-muted"><span>Halaman {{ page }} dari {{ lastPage }}</span><div class="flex gap-2"><button class="rounded-lg border border-line bg-white px-3 py-1.5 font-medium disabled:opacity-40" :disabled="loading || page <= 1" @click="page--; loadAudit()">Sebelumnya</button><button class="rounded-lg border border-line bg-white px-3 py-1.5 font-medium disabled:opacity-40" :disabled="loading || page >= lastPage" @click="page++; loadAudit()">Berikutnya</button></div></div>
  </section>
</template>
