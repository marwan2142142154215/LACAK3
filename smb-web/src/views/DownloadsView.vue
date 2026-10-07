<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Download, Package, RefreshCw } from '@lucide/vue'
import dayjs from 'dayjs'
import 'dayjs/locale/id'
import PageHeader from '../components/PageHeader.vue'
import { api, apiMessage, type ApiEnvelope, type Paginated } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import type { Site, Team } from '../types/platform'

dayjs.locale('id')

interface ReleaseArtifact {
  id: string
  app_name: string
  version: string
  version_code: number
  minimum_android_api: number
  released_at: string
  checksum_sha256: string
}

interface RegistrationCode {
  id: string
  code: string
  site_id: number
  team_id: number
  expires_at: string
  single_use: boolean
}

const auth = useAuthStore()
const artifacts = ref<ReleaseArtifact[]>([])
const sites = ref<Site[]>([])
const teams = ref<Team[]>([])
const loading = ref(false)
const registrationBusy = ref(false)
const downloadBusyId = ref('')
const error = ref('')
const registrationError = ref('')
const siteId = ref('')
const teamId = ref('')
const registrationCode = ref<RegistrationCode | null>(null)
const copied = ref(false)

const activeSites = computed(() => sites.value.filter((site) => site.is_active))
const selectedTeams = computed(() => teams.value.filter((team) => team.is_active && String(team.site_id) === siteId.value))
const registrationSite = computed(() => activeSites.value.find((site) => String(site.id) === siteId.value))
const registrationTeam = computed(() => selectedTeams.value.find((team) => String(team.id) === teamId.value))
const trackerCodeReady = computed(() => Boolean(registrationCode.value && dayjs(registrationCode.value.expires_at).isAfter(dayjs())))

function appKey(name: string): string {
  return name.toLowerCase().replace(/[^a-z0-9]/g, '')
}

function isTracker(artifact: ReleaseArtifact): boolean {
  return appKey(artifact.app_name) === 'smblacak'
}

async function load() {
  loading.value = true
  error.value = ''
  const requests: Promise<unknown>[] = [api.get<ApiEnvelope<ReleaseArtifact[]>>('/api/v1/releases')]
  if (auth.can('devices.create') && auth.can('sites.view')) {
    requests.push(api.get<ApiEnvelope<Paginated<Site>>>('/api/v1/sites', { params: { per_page: 100 } }))
    requests.push(api.get<ApiEnvelope<Paginated<Team>>>('/api/v1/teams', { params: { per_page: 100 } }))
  }

  try {
    const [releaseResult, siteResult, teamResult] = await Promise.allSettled(requests)
    if (releaseResult.status !== 'fulfilled') throw releaseResult.reason
    artifacts.value = (releaseResult.value as { data: ApiEnvelope<ReleaseArtifact[]> }).data.data

    if (siteResult?.status === 'fulfilled') {
      sites.value = (siteResult.value as { data: ApiEnvelope<Paginated<Site>> }).data.data.items
    } else if (siteResult?.status === 'rejected') {
      throw siteResult.reason
    }
    if (teamResult?.status === 'fulfilled') {
      teams.value = (teamResult.value as { data: ApiEnvelope<Paginated<Team>> }).data.data.items
    } else if (teamResult?.status === 'rejected') {
      throw teamResult.reason
    }
  } catch (requestError) {
    error.value = apiMessage(requestError)
  } finally {
    loading.value = false
  }
}

async function generateRegistrationCode() {
  if (!auth.can('devices.create')) {
    registrationError.value = 'Akun ini tidak memiliki izin untuk membuat kode registrasi.'
    return
  }
  if (!registrationSite.value || !registrationTeam.value) {
    registrationError.value = 'Pilih Site dan Team aktif yang sesuai.'
    return
  }

  registrationBusy.value = true
  registrationError.value = ''
  registrationCode.value = null
  try {
    const response = await api.post<ApiEnvelope<RegistrationCode>>('/api/v1/device-registration-codes', {
      site_id: registrationSite.value.id,
      team_id: registrationTeam.value.id,
    })
    registrationCode.value = response.data.data
  } catch (requestError) {
    registrationError.value = apiMessage(requestError)
  } finally {
    registrationBusy.value = false
  }
}

async function copyRegistrationCode() {
  if (!registrationCode.value) return
  try {
    await navigator.clipboard.writeText(registrationCode.value.code)
    copied.value = true
    window.setTimeout(() => { copied.value = false }, 1600)
  } catch {
    registrationError.value = 'Clipboard tidak tersedia. Pilih dan salin kode secara manual.'
  }
}

async function download(artifact: ReleaseArtifact) {
  error.value = ''
  if (isTracker(artifact) && !trackerCodeReady.value) {
    error.value = 'Buat kode registrasi Site/Team yang masih berlaku sebelum mengunduh SMB Lacak.'
    return
  }

  downloadBusyId.value = artifact.id
  try {
    const params = isTracker(artifact) ? { registration_code_id: registrationCode.value!.id } : undefined
    const response = await api.get<ApiEnvelope<{ url: string; expires_at: string }>>(
      `/api/v1/releases/${encodeURIComponent(artifact.id)}/download`,
      { params },
    )
    window.location.assign(response.data.data.url)
  } catch (requestError) {
    error.value = apiMessage(requestError)
  } finally {
    downloadBusyId.value = ''
  }
}

async function revokeRegistration() {
  const issued = registrationCode.value
  if (!issued || registrationBusy.value) return
  if (!window.confirm('Cabut kode registrasi ini? Perangkat yang belum terdaftar tidak dapat memakai kode tersebut.')) return
  registrationBusy.value = true
  registrationError.value = ''
  try {
    await api.post(`/api/v1/device-registration-codes/${encodeURIComponent(issued.id)}/revoke`)
    registrationCode.value = null
    teamId.value = ''
  } catch (requestError) {
    registrationError.value = apiMessage(requestError)
  } finally {
    registrationBusy.value = false
  }
}

onMounted(load)
</script>

<template>
  <PageHeader eyebrow="Distribusi bertanda tangan" title="Pusat aplikasi SMB" description="Unduh build SMB yang sudah diverifikasi. SMB Lacak memakai kode sekali pakai yang terikat Site dan Team sebelum perangkat didaftarkan." />

  <div v-if="error" role="alert" class="mb-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ error }}</div>

  <section v-if="auth.can('devices.create') && auth.can('sites.view')" class="mb-6 overflow-hidden rounded-[26px] border border-white bg-white p-4 shadow-[0_12px_38px_rgba(16,37,65,0.07)] sm:p-6">
    <div class="flex items-start gap-4 rounded-2xl bg-gradient-to-br from-[#102541] via-[#153b5e] to-[#176b75] p-5 text-white sm:p-6">
      <div class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl border border-white/15 bg-white/10 text-teal-100 shadow-inner"><Package :size="22" /></div>
      <div>
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-teal-100">Enrollment perangkat</p>
        <h2 class="mt-1 text-lg font-bold tracking-tight text-white sm:text-xl">Daftarkan SMB Lacak ke Site</h2>
        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-100">Server menerbitkan kode sekali pakai selama 15 menit. Site dan Team ditentukan server saat perangkat didaftarkan.</p>
      </div>
    </div>

    <div class="mt-5 grid gap-4 sm:grid-cols-2">
      <label class="text-sm font-semibold text-ink">Site
        <select v-model="siteId" class="mt-2.5 h-12 w-full rounded-xl border border-[#d7e0eb] bg-white px-3.5 text-sm font-medium text-ink shadow-sm outline-none transition focus:border-teal focus:ring-4 focus:ring-teal/10 disabled:bg-canvas" :disabled="registrationBusy || Boolean(registrationCode)" @change="teamId = ''">
          <option value="">Pilih Site aktif</option>
          <option v-for="site in activeSites" :key="site.id" :value="String(site.id)">{{ site.name }} ({{ site.code }})</option>
        </select>
      </label>
      <label class="text-sm font-semibold text-ink">Team
        <select v-model="teamId" class="mt-2.5 h-12 w-full rounded-xl border border-[#d7e0eb] bg-white px-3.5 text-sm font-medium text-ink shadow-sm outline-none transition focus:border-teal focus:ring-4 focus:ring-teal/10 disabled:bg-canvas" :disabled="!siteId || registrationBusy || Boolean(registrationCode)">
          <option value="">Pilih Team aktif</option>
          <option v-for="team in selectedTeams" :key="team.id" :value="String(team.id)">{{ team.name }}</option>
        </select>
      </label>
    </div>

    <div v-if="registrationError" role="alert" class="mt-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ registrationError }}</div>

    <div v-if="registrationCode" class="mt-5 rounded-2xl border border-teal/20 bg-gradient-to-br from-[#effaf7] to-[#f4f8fc] p-4 sm:p-5">
      <div class="grid gap-4 text-sm sm:grid-cols-3">
        <div><p class="text-muted">Site</p><p class="mt-1 font-semibold text-ink">{{ registrationSite?.name ?? 'Tidak diketahui' }}</p></div>
        <div><p class="text-muted">Team</p><p class="mt-1 font-semibold text-ink">{{ registrationTeam?.name ?? 'Tidak diketahui' }}</p></div>
        <div><p class="text-muted">Status / kedaluwarsa</p><p class="mt-1 font-semibold text-ink">{{ trackerCodeReady ? 'UNUSED' : 'EXPIRED' }} · {{ dayjs(registrationCode.expires_at).format('D MMM YYYY, HH:mm') }}</p></div>
      </div>
      <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-teal/15 pt-3">
        <code class="break-all font-mono text-base font-semibold tracking-wide text-ink">{{ registrationCode.code }}</code>
        <button class="rounded-xl border border-[#d5e0e9] bg-white px-4 py-2.5 text-sm font-semibold text-ink shadow-sm transition hover:border-teal hover:bg-teal/5" @click="copyRegistrationCode">{{ copied ? 'Tersalin' : 'Salin kode' }}</button>
      </div>
      <div class="mt-4 flex flex-wrap gap-2">
        <button class="rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-semibold text-rose hover:bg-rose-50 disabled:opacity-50" :disabled="registrationBusy" @click="revokeRegistration">{{ registrationBusy ? 'Mencabut…' : 'Cabut kode dan buat baru' }}</button>
        <span class="self-center text-xs text-muted">Unduh SMB Lacak di daftar rilis di bawah. Kode akan dimasukkan saat registrasi aplikasi.</span>
      </div>
    </div>

    <button v-else class="mt-5 inline-flex min-h-12 items-center gap-2 rounded-xl bg-gradient-to-r from-navy to-ocean px-5 py-3 text-sm font-bold text-white shadow-[0_8px_20px_rgba(16,37,65,0.18)] transition hover:-translate-y-0.5 hover:shadow-[0_12px_24px_rgba(16,37,65,0.22)] disabled:cursor-not-allowed disabled:opacity-50" :disabled="registrationBusy || !siteId || !teamId" @click="generateRegistrationCode">
      {{ registrationBusy ? 'Membuat kode…' : 'Buat kode dan sesi registrasi' }}
    </button>
  </section>

  <section class="overflow-hidden rounded-[26px] border border-white bg-white shadow-[0_12px_38px_rgba(16,37,65,0.07)]">
    <div class="flex items-center justify-between border-b border-line bg-gradient-to-r from-white to-[#f4f8fb] px-5 py-5 sm:px-6">
      <div class="flex items-center gap-3"><div class="grid h-10 w-10 place-items-center rounded-xl bg-teal/10 text-teal"><Package :size="19" /></div><div><p class="text-base font-bold text-ink">Rilis yang dipublikasikan</p><p class="mt-0.5 text-xs font-medium text-slate-600">File diverifikasi sebelum tersedia untuk diunduh</p></div></div>
      <button class="rounded-xl border border-line bg-white p-2.5 text-slate-700 shadow-sm transition hover:bg-canvas" aria-label="Muat ulang rilis" :disabled="loading" @click="load"><RefreshCw :size="16" :class="loading ? 'animate-spin' : ''" /></button>
    </div>
    <div v-if="loading" class="px-5 py-12 text-center text-sm text-muted">Memeriksa katalog rilis…</div>
    <div v-else-if="!artifacts.length" class="px-5 py-12 text-center">
      <Package :size="24" class="mx-auto text-slate-500" />
      <p class="mt-3 text-sm font-semibold text-ink">Belum ada file rilis yang dipublikasikan</p>
      <p class="mx-auto mt-1 max-w-xl text-xs leading-5 text-muted">Kode Site/Team sudah dibuat oleh server. Unduhan akan aktif setelah APK atau installer bertanda tangan diterbitkan dan storage terverifikasi. Build debug lokal tidak diberi label rilis resmi.</p>
    </div>
    <div v-else class="divide-y divide-line">
      <article v-for="artifact in artifacts" :key="artifact.id" class="mx-4 my-3 flex flex-wrap items-center justify-between gap-4 rounded-2xl border border-[#e3eaf1] bg-gradient-to-r from-white to-[#f8fbfd] px-4 py-4 transition hover:border-teal/25 hover:shadow-card sm:mx-5 sm:px-5">
        <div class="flex min-w-0 flex-1 items-start gap-3">
          <div class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl bg-[#eaf3f8] text-ocean"><Package :size="21" /></div>
          <div class="min-w-0"><h2 class="text-base font-bold text-ink">{{ artifact.app_name }} · {{ artifact.version }}</h2>
          <p class="mt-1 text-sm text-slate-700">Versi kode {{ artifact.version_code }} · Android API {{ artifact.minimum_android_api }}+</p>
          <p class="mt-1 break-all font-mono text-xs leading-5 text-slate-600">SHA-256 {{ artifact.checksum_sha256 }}</p>
          <p v-if="isTracker(artifact)" class="mt-2 inline-flex rounded-full bg-teal/10 px-2.5 py-1 text-xs font-semibold text-teal">{{ trackerCodeReady ? `Terikat ke ${registrationSite?.name} / ${registrationTeam?.name}` : 'Perlu kode Site/Team aktif' }}</p></div>
        </div>
        <button class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-navy to-ocean px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md disabled:cursor-not-allowed disabled:opacity-45" :disabled="downloadBusyId === artifact.id || (isTracker(artifact) && !trackerCodeReady)" @click="download(artifact)">
          <Download :size="14" />{{ downloadBusyId === artifact.id ? 'Menyiapkan…' : 'Unduh' }}
        </button>
      </article>
    </div>
  </section>
</template>
