<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import dayjs from 'dayjs'
import { RefreshCw, ShieldAlert } from '@lucide/vue'
import PageHeader from '../components/PageHeader.vue'
import StatusPill from '../components/StatusPill.vue'
import { api, apiMessage, type ApiEnvelope, type Paginated } from '../lib/api'
import type { NetworkViolation } from '../types/platform'

const rows = ref<NetworkViolation[]>([])
const status = ref('OPEN')
const currentPage = ref(1)
const lastPage = ref(1)
const total = ref(0)
const loading = ref(false)
const error = ref('')
const reasonLabels: Record<string, string> = {
  WIFI_SSID_MISMATCH: 'Wi-Fi di luar daftar izin',
  PUBLIC_IP_OUT_OF_RANGE: 'IP publik di luar rentang izin',
  WIFI_SSID_UNAVAILABLE: 'Nama Wi-Fi tidak dapat diverifikasi',
  PUBLIC_IP_UNAVAILABLE: 'IP publik tidak dapat diverifikasi',
}
const emptyLabel = computed(() => status.value === 'OPEN' ? 'Tidak ada pelanggaran terbuka.' : 'Belum ada pelanggaran terselesaikan.')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get<ApiEnvelope<Paginated<NetworkViolation>>>('/api/v1/network-violations', {
      params: { page: currentPage.value, per_page: 25, status: status.value },
    })
    rows.value = response.data.data.items
    currentPage.value = response.data.data.pagination.current_page
    lastPage.value = response.data.data.pagination.last_page
    total.value = response.data.data.pagination.total
  } catch (requestError) { error.value = apiMessage(requestError) } finally { loading.value = false }
}

onMounted(load)
</script>

<template>
  <PageHeader eyebrow="Kepatuhan jaringan" title="Pelanggaran jaringan" description="Insiden dibuat berdasarkan SSID dan alamat IP yang dilaporkan perangkat serta diamati server. Status yang tidak dapat diverifikasi ditampilkan terpisah." />
  <div v-if="error" role="alert" class="mb-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ error }}</div>
  <section class="overflow-hidden rounded-2xl border border-line bg-white shadow-card">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-4">
      <div class="flex items-center gap-2 text-sm font-semibold text-ink"><ShieldAlert :size="17" class="text-amber" />{{ total }} insiden</div>
      <div class="flex items-center gap-2"><select v-model="status" aria-label="Status pelanggaran" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" @change="currentPage = 1; load()"><option value="OPEN">Terbuka</option><option value="RESOLVED">Terselesaikan</option></select><button class="rounded-lg border border-line p-2 text-muted hover:bg-canvas" aria-label="Muat ulang pelanggaran" :disabled="loading" @click="load"><RefreshCw :size="15" :class="loading ? 'animate-spin' : ''" /></button></div>
    </div>
    <div v-if="loading && !rows.length" class="px-5 py-14 text-center text-sm text-muted">Memuat insiden dari API…</div>
    <div v-else-if="!rows.length" class="px-5 py-14 text-center"><ShieldAlert :size="24" class="mx-auto text-slate-500" /><p class="mt-3 text-sm font-semibold text-ink">{{ emptyLabel }}</p><p class="mt-1 text-xs text-muted">Periksa heartbeat dan kebijakan jaringan site jika perangkat seharusnya muncul di sini.</p></div>
    <div v-else class="divide-y divide-line">
      <article v-for="row in rows" :key="row.id" class="grid gap-3 px-5 py-4 md:grid-cols-[1fr_1fr_auto] md:items-center">
        <div class="min-w-0"><p class="truncate text-sm font-semibold text-ink">{{ row.device?.name ?? row.device_id }}</p><p class="mt-1 text-xs text-muted">{{ row.site?.name ?? 'Site' }} · {{ row.device_id }}</p></div>
        <div><p class="text-sm font-medium text-ink">{{ reasonLabels[row.reason] ?? row.reason }}</p><p class="mt-1 break-all font-mono text-xs text-muted">{{ row.observed_value ?? 'Tidak tersedia' }} · {{ row.occurrences }} laporan</p></div>
        <div class="flex items-center justify-between gap-3 md:block md:text-right"><StatusPill :status="row.status" /><p class="mt-1 text-xs text-muted">{{ dayjs(row.last_seen_at).format('D MMM YYYY, HH:mm:ss') }}</p></div>
      </article>
    </div>
    <div class="flex items-center justify-between border-t border-line bg-canvas px-5 py-3 text-xs text-muted"><span>Halaman {{ currentPage }} dari {{ lastPage }}</span><div class="flex gap-2"><button class="rounded-md border border-line bg-white px-2.5 py-1.5 disabled:opacity-40" :disabled="currentPage <= 1 || loading" @click="currentPage--; load()">Sebelumnya</button><button class="rounded-md border border-line bg-white px-2.5 py-1.5 disabled:opacity-40" :disabled="currentPage >= lastPage || loading" @click="currentPage++; load()">Berikutnya</button></div></div>
  </section>
</template>
