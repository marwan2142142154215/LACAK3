<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import ApexCharts from 'vue3-apexcharts'
import { Activity, ArrowDownRight, ArrowRight, ArrowUpRight, MapPin, RefreshCw, Smartphone } from '@lucide/vue'
import dayjs from 'dayjs'
import relativeTimePlugin from 'dayjs/plugin/relativeTime'
import 'dayjs/locale/id'
import PageHeader from '../components/PageHeader.vue'
import StatusPill from '../components/StatusPill.vue'
import { api, apiMessage, type ApiEnvelope, type Paginated } from '../lib/api'
import type { Device, DeviceStatus } from '../types/platform'

dayjs.extend(relativeTimePlugin)
dayjs.locale('id')

const statuses: DeviceStatus[] = ['ONLINE', 'DEGRADED', 'OFFLINE', 'UNKNOWN']
const counts = ref<Record<DeviceStatus, number>>({ ONLINE: 0, DEGRADED: 0, OFFLINE: 0, UNKNOWN: 0 })
const devices = ref<Device[]>([])
const total = computed(() => Object.values(counts.value).reduce((sum, count) => sum + count, 0))
const loading = ref(true)
const error = ref('')

const chartOptions = computed(() => ({
  chart: { type: 'donut' as const, fontFamily: 'Inter, ui-sans-serif, system-ui', toolbar: { show: false } },
  labels: ['Online', 'Degraded', 'Offline', 'Unknown'],
  colors: ['#168477', '#e5a239', '#8795a8', '#c6d0dc'],
  stroke: { width: 4, colors: ['#fff'] },
  dataLabels: { enabled: false },
  legend: { show: false },
  plotOptions: { pie: { donut: { size: '76%', labels: { show: false } } } },
  tooltip: { theme: 'light' as const },
}))
const chartSeries = computed(() => statuses.map((status) => counts.value[status]))

async function loadOverview() {
  loading.value = true
  error.value = ''
  try {
    const [summaryResults, listResponse] = await Promise.all([
      Promise.all(statuses.map((status) => api.get<ApiEnvelope<Paginated<Device>>>('/api/v1/devices', { params: { status, per_page: 1 } }))),
      api.get<ApiEnvelope<Paginated<Device>>>('/api/v1/devices', { params: { per_page: 6, page: 1 } }),
    ])
    statuses.forEach((status, index) => { counts.value[status] = summaryResults[index].data.data.pagination.total })
    devices.value = listResponse.data.data.items
  } catch (loadError) {
    error.value = apiMessage(loadError)
  } finally {
    loading.value = false
  }
}

function relativeTime(value: string | null) {
  return value ? dayjs(value).fromNow() : 'Belum pernah terhubung'
}

onMounted(loadOverview)
</script>

<template>
  <PageHeader eyebrow="Ringkasan" title="Pantau armada perangkat" description="Status dihitung dari heartbeat dan waktu penerimaan server. Perangkat tanpa sinyal baru tidak ditampilkan sebagai online.">
    <RouterLink to="/devices" class="inline-flex items-center gap-2 rounded-xl bg-navy px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-ocean"><Smartphone :size="16" /> Daftar perangkat</RouterLink>
  </PageHeader>

  <div v-if="error" class="mb-5 flex items-center justify-between gap-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">
    <span>{{ error }}</span>
    <button class="inline-flex shrink-0 items-center gap-1.5 font-semibold" @click="loadOverview"><RefreshCw :size="14" /> Coba lagi</button>
  </div>

  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <article v-for="card in [
      { label: 'Total perangkat', value: total, status: 'Terdaftar dalam cakupan akses', icon: Smartphone, accent: 'text-ocean bg-blue-50', trend: 'Inventory server' },
      { label: 'Online', value: counts.ONLINE, status: 'Heartbeat terbaru dan sesi aktif', icon: Activity, accent: 'text-teal bg-mint', trend: 'Status dari server' },
      { label: 'Offline', value: counts.OFFLINE, status: 'Melewati batas waktu offline', icon: ArrowDownRight, accent: 'text-slate-600 bg-slate-100', trend: 'Perlu diperiksa' },
      { label: 'Degraded / Unknown', value: counts.DEGRADED + counts.UNKNOWN, status: 'Sinyal terlambat atau belum cukup', icon: ArrowUpRight, accent: 'text-amber bg-amber-50', trend: 'Status terbatas' },
    ]" :key="card.label" class="group rounded-[22px] border border-white bg-gradient-to-br from-white to-[#f8fbfd] p-5 shadow-[0_10px_30px_rgba(16,37,65,0.045)] transition hover:-translate-y-0.5 hover:border-teal/20 hover:shadow-[0_16px_36px_rgba(16,37,65,0.08)]">
      <div class="flex items-start justify-between">
        <div><p class="text-sm font-semibold text-slate-700">{{ card.label }}</p><p class="mt-3 text-[34px] font-bold tracking-tight text-ink">{{ loading ? '—' : card.value }}</p></div>
        <div class="grid h-12 w-12 place-items-center rounded-2xl shadow-sm transition group-hover:scale-105" :class="card.accent"><component :is="card.icon" :size="21" /></div>
      </div>
      <p class="mt-2 text-sm text-slate-600">{{ card.status }}</p>
      <p class="mt-4 border-t border-line pt-3 text-xs font-semibold text-slate-600">{{ card.trend }}</p>
    </article>
  </div>

  <div class="mt-6 grid gap-5 xl:grid-cols-[0.72fr_1.28fr]">
    <section class="rounded-[24px] border border-white bg-gradient-to-br from-white to-[#f8fbfd] p-5 shadow-[0_12px_34px_rgba(16,37,65,0.055)] sm:p-6">
      <div class="flex items-start justify-between gap-3">
        <div><p class="text-base font-semibold text-ink">Kondisi perangkat</p><p class="mt-1 text-xs text-muted">Distribusi status aktual dari API Laravel</p></div>
        <span class="rounded-lg bg-canvas px-2.5 py-1 text-[11px] font-medium text-muted">{{ total }} perangkat</span>
      </div>
      <div v-if="total" class="relative mt-3 flex items-center justify-center">
        <ApexCharts type="donut" width="228" :options="chartOptions" :series="chartSeries" />
        <div class="pointer-events-none absolute text-center"><p class="text-3xl font-semibold tracking-tight text-ink">{{ total }}</p><p class="mt-0.5 text-[11px] text-muted">perangkat</p></div>
      </div>
      <div v-else-if="loading" class="grid h-56 place-items-center text-sm text-muted">Memuat status server…</div>
      <div v-else class="grid h-56 place-items-center text-sm text-muted">Belum ada perangkat di cakupan akses Anda.</div>
      <div class="mt-2 grid grid-cols-2 gap-x-4 gap-y-3 border-t border-line pt-4">
        <div v-for="(status, index) in statuses" :key="status" class="flex items-center justify-between gap-2 text-xs">
          <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full" :class="['bg-teal', 'bg-amber-400', 'bg-slate-400', 'bg-slate-300'][index]" /><span class="text-muted">{{ ['Online', 'Degraded', 'Offline', 'Unknown'][index] }}</span></div>
          <span class="font-semibold text-ink">{{ counts[status] }}</span>
        </div>
      </div>
    </section>

    <section class="overflow-hidden rounded-[24px] border border-white bg-white shadow-[0_12px_34px_rgba(16,37,65,0.055)]">
      <div class="flex items-center justify-between border-b border-line bg-gradient-to-r from-white to-[#f7fafc] px-5 py-5 sm:px-6">
        <div><p class="text-base font-semibold text-ink">Perangkat terbaru</p><p class="mt-1 text-xs text-muted">Terakhir diperbarui berdasarkan data server</p></div>
        <RouterLink to="/devices" class="inline-flex items-center gap-1 text-xs font-semibold text-ocean hover:text-teal">Lihat semua <ArrowRight :size="14" /></RouterLink>
      </div>
      <div v-if="loading" class="space-y-4 p-5"><div v-for="n in 4" :key="n" class="h-12 animate-pulse rounded-xl bg-canvas" /></div>
      <div v-else-if="!devices.length" class="px-6 py-14 text-center"><div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-canvas text-slate-600"><Smartphone :size="22" /></div><p class="mt-3 text-sm font-semibold text-ink">Belum ada perangkat</p><p class="mt-1 text-xs text-muted">Perangkat akan muncul setelah registrasi berhasil.</p></div>
      <div v-else class="divide-y divide-line">
        <RouterLink v-for="device in devices" :key="device.id" :to="`/devices/${device.id}`" class="flex items-center gap-3 px-5 py-4 transition hover:bg-[#f4faf9] sm:px-6">
          <div class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl border border-ocean/5 bg-gradient-to-br from-[#edf5fa] to-[#e5f4f1] text-ocean"><Smartphone :size="19" /></div>
          <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-ink">{{ device.name }}</p><p class="mt-1 truncate text-[11px] text-muted">{{ device.site?.name ?? 'Lokasi belum tersedia' }} · {{ device.team?.name ?? 'Tanpa tim' }}</p></div>
          <div class="hidden text-right sm:block"><StatusPill :status="device.status" /><p class="mt-1.5 text-[10px] text-muted">{{ relativeTime(device.last_seen_at) }}</p></div>
          <div class="sm:hidden"><StatusPill :status="device.status" /></div>
        </RouterLink>
      </div>
      <div class="flex items-center justify-between border-t border-line bg-[#fafbfc] px-5 py-3.5 sm:px-6"><p class="text-[11px] text-muted">Status dan lokasi mengikuti izin perangkat dan kebijakan Android.</p><RouterLink to="/map" class="hidden items-center gap-1 text-xs font-semibold text-ocean sm:inline-flex">Buka peta <MapPin :size="13" /></RouterLink></div>
    </section>
  </div>
</template>
