<script setup lang="ts">
import { computed, h, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  createColumnHelper, FlexRender, getCoreRowModel, useVueTable,
} from '@tanstack/vue-table'
import { ArrowDown, ArrowUp, Copy, Filter, Plus, RefreshCw, Search, Smartphone, X } from '@lucide/vue'
import dayjs from 'dayjs'
import 'dayjs/locale/id'
import PageHeader from '../components/PageHeader.vue'
import StatusPill from '../components/StatusPill.vue'
import { api, apiMessage, type ApiEnvelope, type Paginated } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import type { Device, Site, Team } from '../types/platform'

dayjs.locale('id')
const auth = useAuthStore()
const router = useRouter()
const devices = ref<Device[]>([])
const sites = ref<Site[]>([])
const teams = ref<Team[]>([])
const pagination = ref<Paginated<Device>['pagination']>({ total: 0, current_page: 1, last_page: 1, per_page: 25 })
const loading = ref(false)
const error = ref('')
const search = ref('')
const status = ref('')
const siteId = ref('')
const teamId = ref('')
const androidApi = ref('')
const batteryMax = ref('')
const lastSeenBefore = ref('')
const page = ref(1)
const registrationOpen = ref(false)
const registrationBusy = ref(false)
const registrationError = ref('')
const registrationCode = ref<{ id: string; code: string; expires_at: string } | null>(null)
const registrationSiteId = ref('')
const registrationTeamId = ref('')
const copied = ref(false)
const registrationNotice = ref('')
let searchTimer: number | undefined

const selectedTeams = computed(() => teams.value.filter((team) => !registrationSiteId.value || String(team.site_id) === registrationSiteId.value))
const activeFilters = computed(() => [status.value, siteId.value, teamId.value, androidApi.value, batteryMax.value, lastSeenBefore.value].filter(Boolean).length)

const columnHelper = createColumnHelper<Device>()
const columns = [
  columnHelper.accessor('name', {
    header: 'Perangkat',
    cell: (info) => h('div', { class: 'min-w-[190px]' }, [
      h('a', {
        href: `/devices/${info.row.original.id}`,
        class: 'block truncate text-sm font-semibold text-ink hover:text-ocean',
        onClick: (event: MouseEvent) => { event.preventDefault(); void router.push(`/devices/${info.row.original.id}`) },
      }, info.getValue()),
      h('span', { class: 'mt-1 block max-w-[240px] truncate font-mono text-[10px] text-slate-600' }, info.row.original.id),
    ]),
  }),
  columnHelper.accessor((row) => row.site?.name ?? '—', { id: 'site', header: 'Lokasi', cell: (info) => h('div', { class: 'min-w-[125px]' }, [h('p', { class: 'text-xs font-medium text-ink' }, info.getValue()), h('p', { class: 'mt-1 text-[10px] text-muted' }, info.row.original.team?.name ?? 'Tanpa tim')]) }),
  columnHelper.accessor('status', { header: 'Status', cell: (info) => h(StatusPill, { status: info.getValue() }) }),
  columnHelper.accessor('battery_percent', { header: 'Baterai', cell: (info) => h('span', { class: 'text-xs font-medium text-ink' }, info.getValue() === null ? '—' : `${info.getValue()}%`) }),
  columnHelper.accessor('last_seen_at', { header: 'Terakhir terlihat', cell: (info) => h('div', { class: 'min-w-[132px]' }, [h('p', { class: 'text-xs font-medium text-ink' }, info.getValue() ? dayjs(info.getValue()).fromNow() : 'Belum ada heartbeat'), h('p', { class: 'mt-1 text-[10px] text-muted' }, info.row.original.network_type ?? 'Jaringan tidak diketahui')]) }),
  columnHelper.accessor('android_api', { header: 'Android', cell: (info) => h('span', { class: 'text-xs text-muted' }, info.getValue() ? `API ${info.getValue()}` : '—') }),
]
const table = useVueTable({
  get data() { return devices.value },
  columns,
  getCoreRowModel: getCoreRowModel(),
})

async function loadFilters() {
  const [siteResult, teamResult] = await Promise.allSettled([
    api.get<ApiEnvelope<Paginated<Site>>>('/api/v1/sites', { params: { per_page: 100 } }),
    api.get<ApiEnvelope<Paginated<Team>>>('/api/v1/teams', { params: { per_page: 100 } }),
  ])
  if (siteResult.status === 'fulfilled') sites.value = siteResult.value.data.data.items
  if (teamResult.status === 'fulfilled') teams.value = teamResult.value.data.data.items
}

async function loadDevices() {
  loading.value = true
  error.value = ''
  const params: Record<string, string | number> = { page: page.value, per_page: pagination.value.per_page }
  if (search.value.trim()) params.search = search.value.trim()
  if (status.value) params.status = status.value
  if (siteId.value) params.site_id = Number(siteId.value)
  if (teamId.value) params.team_id = Number(teamId.value)
  if (androidApi.value) params.android_api = Number(androidApi.value)
  if (batteryMax.value) params.battery_max = Number(batteryMax.value)
  if (lastSeenBefore.value) params.last_seen_before = new Date(lastSeenBefore.value).toISOString()
  try {
    const response = await api.get<ApiEnvelope<Paginated<Device>>>('/api/v1/devices', { params })
    devices.value = response.data.data.items
    pagination.value = response.data.data.pagination
  } catch (loadError) {
    error.value = apiMessage(loadError)
    devices.value = []
  } finally {
    loading.value = false
  }
}

function scheduleSearch() {
  if (searchTimer) window.clearTimeout(searchTimer)
  searchTimer = window.setTimeout(() => { page.value = 1; void loadDevices() }, 300)
}

function clearFilters() {
  search.value = ''; status.value = ''; siteId.value = ''; teamId.value = ''; androidApi.value = ''; batteryMax.value = ''; lastSeenBefore.value = ''; page.value = 1
  void loadDevices()
}

async function openRegistration() {
  registrationOpen.value = true
  registrationError.value = ''
  registrationNotice.value = ''
  registrationCode.value = null
  if (!sites.value.length) await loadFilters()
}

async function generateRegistrationCode() {
  if (!registrationSiteId.value || !registrationTeamId.value) {
    registrationError.value = 'Pilih lokasi dan tim sebelum membuat kode.'
    return
  }
  registrationBusy.value = true
  registrationError.value = ''
  registrationNotice.value = ''
  try {
    const response = await api.post<ApiEnvelope<{ id: string; code: string; expires_at: string }>>('/api/v1/device-registration-codes', {
      site_id: Number(registrationSiteId.value),
      team_id: Number(registrationTeamId.value),
    })
    registrationCode.value = response.data.data
  } catch (requestError) {
    registrationError.value = apiMessage(requestError)
  } finally {
    registrationBusy.value = false
  }
}

async function copyCode() {
  if (!registrationCode.value) return
  try {
    await navigator.clipboard.writeText(registrationCode.value.code)
    copied.value = true
    window.setTimeout(() => { copied.value = false }, 1600)
  } catch {
    registrationError.value = 'Clipboard tidak tersedia. Pilih dan salin kode secara manual.'
  }
}

async function revokeRegistrationCode() {
  const issued = registrationCode.value
  if (!issued || !window.confirm('Cabut kode registrasi ini? Kode tidak dapat digunakan lagi.')) return
  registrationBusy.value = true
  registrationError.value = ''
  try {
    await api.post(`/api/v1/device-registration-codes/${encodeURIComponent(issued.id)}/revoke`)
    registrationCode.value = null
    registrationNotice.value = 'Kode registrasi telah dicabut di server.'
  } catch (requestError) {
    registrationError.value = apiMessage(requestError)
  } finally {
    registrationBusy.value = false
  }
}

function commandSortIcon(sorted: false | 'asc' | 'desc') {
  return sorted === 'asc' ? ArrowUp : sorted === 'desc' ? ArrowDown : null
}

watch([status, siteId, teamId, androidApi, batteryMax, lastSeenBefore], () => { page.value = 1; void loadDevices() })
onMounted(() => { void loadFilters(); void loadDevices() })
</script>

<template>
  <PageHeader eyebrow="Inventory" title="Perangkat terdaftar" description="Filter perangkat dalam site yang diizinkan. Status dan waktu last-seen dihitung server dari heartbeat tersimpan.">
    <button v-if="auth.can('devices.create')" class="inline-flex items-center gap-2 rounded-xl bg-navy px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-ocean" @click="openRegistration"><Plus :size="16" /> Kode registrasi</button>
  </PageHeader>

  <div class="mb-5 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
    <div class="relative w-full max-w-md"><Search :size="17" class="absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-600" /><input v-model="search" class="w-full rounded-xl border border-line bg-white py-2.5 pl-10 pr-4 text-sm outline-none focus:border-teal focus:ring-4 focus:ring-teal/10" placeholder="Cari nama, model, atau ID perangkat" @input="scheduleSearch" /></div>
    <div class="flex flex-wrap items-center gap-2">
      <select v-model="status" class="filter-select" aria-label="Filter status"><option value="">Semua status</option><option value="ONLINE">Online</option><option value="DEGRADED">Degraded</option><option value="OFFLINE">Offline</option><option value="UNKNOWN">Unknown</option></select>
      <select v-model="siteId" class="filter-select" aria-label="Filter lokasi"><option value="">Semua lokasi</option><option v-for="site in sites" :key="site.id" :value="String(site.id)">{{ site.name }}</option></select>
      <select v-model="teamId" class="filter-select" aria-label="Filter tim"><option value="">Semua tim</option><option v-for="team in teams" :key="team.id" :value="String(team.id)">{{ team.name }}</option></select>
      <select v-model="androidApi" class="filter-select" aria-label="Filter versi Android"><option value="">Semua API</option><option v-for="version in [26, 28, 29, 30, 31, 33, 34, 35, 36]" :key="version" :value="String(version)">API {{ version }}</option></select>
      <select v-model="batteryMax" class="filter-select" aria-label="Filter baterai"><option value="">Semua baterai</option><option value="20">Baterai ≤ 20%</option><option value="40">Baterai ≤ 40%</option><option value="60">Baterai ≤ 60%</option></select>
      <label class="flex items-center gap-2 rounded-xl border border-line bg-white px-3 py-2 text-xs text-muted"><Filter :size="14" /><input v-model="lastSeenBefore" aria-label="Last seen sebelum" class="w-36 border-0 bg-transparent p-0 text-xs text-ink outline-none" type="datetime-local" /></label>
      <button v-if="activeFilters" class="inline-flex items-center gap-1 rounded-lg px-2.5 py-2 text-xs font-semibold text-muted hover:bg-slate-100" @click="clearFilters"><X :size="13" /> Hapus filter ({{ activeFilters }})</button>
      <button class="rounded-lg p-2 text-muted hover:bg-slate-100" aria-label="Muat ulang perangkat" :disabled="loading" @click="loadDevices"><RefreshCw :size="15" :class="loading ? 'animate-spin' : ''" /></button>
    </div>
  </div>

  <div v-if="error" class="mb-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ error }}</div>
  <div class="overflow-hidden rounded-2xl border border-line bg-white shadow-card">
    <div class="overflow-x-auto">
      <table class="w-full min-w-[920px] border-collapse text-left">
        <thead class="bg-[#fafbfc]">
          <tr v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id" class="border-b border-line">
            <th v-for="header in headerGroup.headers" :key="header.id" class="whitespace-nowrap px-5 py-3.5 text-[10px] font-semibold uppercase tracking-[0.1em] text-muted">
              <button v-if="header.column.getCanSort()" class="inline-flex items-center gap-1.5" @click="header.column.toggleSorting()"><FlexRender v-if="!header.isPlaceholder" :render="header.column.columnDef.header" :props="header.getContext()" /><component :is="commandSortIcon(header.column.getIsSorted())" v-if="commandSortIcon(header.column.getIsSorted())" :size="12" /></button>
              <FlexRender v-else-if="!header.isPlaceholder" :render="header.column.columnDef.header" :props="header.getContext()" />
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading && !devices.length"><td colspan="6" class="px-5 py-14 text-center text-sm text-muted">Mengambil data perangkat dari Laravel API…</td></tr>
          <tr v-else-if="!devices.length"><td colspan="6" class="px-5 py-14 text-center"><div class="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-canvas text-slate-600"><Smartphone :size="22" /></div><p class="mt-3 text-sm font-semibold text-ink">Tidak ada perangkat yang cocok</p><p class="mt-1 text-xs text-muted">Ubah filter atau periksa akses site pada akun Anda.</p></td></tr>
          <tr v-for="row in table.getRowModel().rows" :key="row.id" class="border-b border-line last:border-0 hover:bg-[#fbfcfd]">
            <td v-for="cell in row.getVisibleCells()" :key="cell.id" class="px-5 py-4 align-middle"><FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" /></td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="flex flex-col gap-3 border-t border-line bg-[#fafbfc] px-5 py-3.5 sm:flex-row sm:items-center sm:justify-between">
      <p class="text-xs text-muted">{{ pagination.total ? `Menampilkan ${(pagination.current_page - 1) * pagination.per_page + 1}–${Math.min(pagination.current_page * pagination.per_page, pagination.total)} dari ${pagination.total}` : '0 perangkat' }}</p>
      <div class="flex items-center justify-between gap-3 sm:justify-end"><label class="text-[11px] text-muted">Baris <select v-model.number="pagination.per_page" class="mx-1 rounded-md border border-line bg-white px-1.5 py-1 text-xs text-ink" @change="page = 1; loadDevices()"><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select></label><button class="rounded-lg border border-line bg-white px-3 py-1.5 text-xs font-medium text-ink disabled:opacity-40" :disabled="page <= 1 || loading" @click="page--; loadDevices()">Sebelumnya</button><span class="text-xs text-muted">{{ page }} / {{ pagination.last_page }}</span><button class="rounded-lg border border-line bg-white px-3 py-1.5 text-xs font-medium text-ink disabled:opacity-40" :disabled="page >= pagination.last_page || loading" @click="page++; loadDevices()">Berikutnya</button></div>
    </div>
  </div>

  <div v-if="registrationOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-navy/40 p-4" @click.self="registrationOpen = false">
    <section class="w-full max-w-lg rounded-2xl border border-line bg-white p-6 shadow-soft">
      <div class="flex items-start justify-between"><div><p class="text-xs font-semibold uppercase tracking-[0.15em] text-teal">Enrollment</p><h2 class="mt-1 text-xl font-semibold text-ink">Buat kode registrasi</h2><p class="mt-1 text-sm text-muted">Kode terikat site/tim, sekali pakai, dan berlaku 15 menit.</p></div><button class="rounded-lg p-2 text-muted hover:bg-canvas" aria-label="Tutup" @click="registrationOpen = false"><X :size="17" /></button></div>
      <p v-if="registrationNotice" role="status" class="mt-4 rounded-xl border border-teal/20 bg-teal/5 px-4 py-3 text-sm text-ink">{{ registrationNotice }}</p>
      <template v-if="registrationCode">
        <div class="mt-6 rounded-xl border border-teal/20 bg-teal/5 p-4"><p class="text-xs font-medium text-muted">Kode satu kali</p><div class="mt-2 flex items-center justify-between gap-3"><code class="break-all font-mono text-lg font-semibold tracking-wide text-ink">{{ registrationCode.code }}</code><button class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-line bg-white px-3 py-2 text-xs font-semibold text-ink" @click="copyCode"><Copy :size="13" />{{ copied ? 'Tersalin' : 'Salin' }}</button></div><p class="mt-3 text-xs text-muted">Berakhir {{ dayjs(registrationCode.expires_at).format('D MMM YYYY, HH:mm') }}. Kode hanya ditampilkan sekarang.</p></div>
        <p v-if="registrationError" role="alert" class="mt-3 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ registrationError }}</p>
        <p class="mt-4 rounded-xl bg-amber-50 px-4 py-3 text-xs leading-5 text-amber-800">Gunakan kode ini pada SMB Lacak yang sudah dipasang pada perangkat yang dikelola. File APK belum diterbitkan melalui download service.</p>
        <button class="mt-3 rounded-lg border border-rose-200 px-3 py-2 text-xs font-semibold text-rose hover:bg-rose-50 disabled:opacity-50" :disabled="registrationBusy" @click="revokeRegistrationCode">{{ registrationBusy ? 'Mencabut…' : 'Cabut kode' }}</button>
      </template>
      <template v-else>
        <div class="mt-6 grid gap-4 sm:grid-cols-2"><label class="text-xs font-semibold text-ink">Lokasi<select v-model="registrationSiteId" class="mt-2 w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-normal" @change="registrationTeamId = ''"><option value="">Pilih lokasi</option><option v-for="site in sites" :key="site.id" :value="String(site.id)">{{ site.name }}</option></select></label><label class="text-xs font-semibold text-ink">Tim<select v-model="registrationTeamId" class="mt-2 w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-normal disabled:bg-canvas" :disabled="!registrationSiteId"><option value="">Pilih tim</option><option v-for="team in selectedTeams" :key="team.id" :value="String(team.id)">{{ team.name }}</option></select></label></div>
        <p v-if="registrationError" role="alert" class="mt-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ registrationError }}</p>
      </template>
      <div class="mt-6 flex justify-end gap-2"><button class="rounded-xl border border-line px-4 py-2.5 text-sm font-medium text-muted hover:bg-canvas" @click="registrationOpen = false">Tutup</button><button v-if="!registrationCode" class="inline-flex items-center gap-2 rounded-xl bg-navy px-4 py-2.5 text-sm font-semibold text-white hover:bg-ocean disabled:opacity-50" :disabled="registrationBusy" @click="generateRegistrationCode"><Plus :size="15" />{{ registrationBusy ? 'Membuat…' : 'Buat kode' }}</button></div>
    </section>
  </div>
</template>

<style scoped>
.filter-select { min-width: 122px; border: 1px solid #e3e9f0; border-radius: 0.75rem; background: white; padding: 0.58rem 0.72rem; font-size: 0.72rem; color: #475569; outline: none; }
.filter-select:focus { border-color: #148477; box-shadow: 0 0 0 3px rgb(20 132 119 / 10%); }
</style>
