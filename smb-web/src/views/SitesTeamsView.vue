<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Building2, Plus, Trash2, Users } from '@lucide/vue'
import PageHeader from '../components/PageHeader.vue'
import { api, apiMessage, type ApiEnvelope, type Paginated } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import type { Site, Team } from '../types/platform'

const auth = useAuthStore()
const sites = ref<Site[]>([])
const teams = ref<Team[]>([])
const sitePagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 })
const teamPagination = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 })
const selectedSiteId = ref('')
const siteForm = ref({ name: '', code: '', timezone: 'Asia/Jakarta', allowed_wifi_ssids: '', allowed_ip_ranges: '' })
const teamForm = ref({ site_id: '', name: '' })
const loading = ref(false)
const busy = ref(false)
const error = ref('')
const notice = ref('')
const canManage = computed(() => auth.can('sites.manage') || auth.can('teams.manage'))

async function loadSites() {
  const response = await api.get<ApiEnvelope<Paginated<Site>>>('/api/v1/sites', { params: { page: sitePagination.value.current_page, per_page: 25 } })
  sites.value = response.data.data.items
  sitePagination.value = response.data.data.pagination
  if (teamForm.value.site_id && !sites.value.some((site) => String(site.id) === teamForm.value.site_id)) teamForm.value.site_id = ''
}

async function loadTeams() {
  const params: Record<string, string | number> = { page: teamPagination.value.current_page, per_page: 25 }
  if (selectedSiteId.value) params.site_id = Number(selectedSiteId.value)
  const response = await api.get<ApiEnvelope<Paginated<Team>>>('/api/v1/teams', { params })
  teams.value = response.data.data.items
  teamPagination.value = response.data.data.pagination
}

async function loadAll() {
  loading.value = true
  error.value = ''
  try { await Promise.all([loadSites(), loadTeams()]) } catch (loadError) { error.value = apiMessage(loadError) } finally { loading.value = false }
}

async function createSite() {
  if (!auth.can('sites.manage')) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    const splitLines = (value: string) => [...new Set(value.split(/[\n,]/).map((item) => item.trim()).filter(Boolean))]
    await api.post('/api/v1/sites', {
      name: siteForm.value.name,
      code: siteForm.value.code,
      timezone: siteForm.value.timezone,
      allowed_wifi_ssids: splitLines(siteForm.value.allowed_wifi_ssids),
      allowed_ip_ranges: splitLines(siteForm.value.allowed_ip_ranges),
    })
    siteForm.value = { name: '', code: '', timezone: 'Asia/Jakarta', allowed_wifi_ssids: '', allowed_ip_ranges: '' }
    notice.value = 'Lokasi berhasil dibuat.'
    await loadSites()
  } catch (requestError) { error.value = apiMessage(requestError) } finally { busy.value = false }
}

async function createTeam() {
  if (!auth.can('teams.manage')) return
  busy.value = true; error.value = ''; notice.value = ''
  try {
    await api.post('/api/v1/teams', { site_id: Number(teamForm.value.site_id), name: teamForm.value.name })
    teamForm.value.name = ''
    notice.value = 'Tim berhasil dibuat.'
    await loadTeams()
  } catch (requestError) { error.value = apiMessage(requestError) } finally { busy.value = false }
}

async function removeSite(site: Site) {
  if (!auth.can('sites.manage') || !window.confirm(`Hapus lokasi “${site.name}”? Lokasi yang masih memiliki tim/perangkat akan ditolak server.`)) return
  try { await api.delete(`/api/v1/sites/${site.id}`); notice.value = 'Lokasi berhasil dihapus.'; await loadSites() } catch (requestError) { error.value = apiMessage(requestError) }
}

async function removeTeam(team: Team) {
  if (!auth.can('teams.manage') || !window.confirm(`Hapus tim “${team.name}”? Tim yang masih memiliki perangkat akan ditolak server.`)) return
  try { await api.delete(`/api/v1/teams/${team.id}`); notice.value = 'Tim berhasil dihapus.'; await loadTeams() } catch (requestError) { error.value = apiMessage(requestError) }
}

onMounted(loadAll)
</script>

<template>
  <PageHeader eyebrow="Organisasi" title="Lokasi dan tim" description="Perubahan melewati validasi dan cakupan site Laravel; perangkat terkait mencegah penghapusan sampai dipindahkan." />
  <div v-if="error" role="alert" class="mb-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ error }}</div>
  <div v-if="notice" role="status" class="mb-4 rounded-xl border border-teal/20 bg-teal/5 px-4 py-3 text-sm text-ink">{{ notice }}</div>
  <div class="grid gap-5 xl:grid-cols-2">
    <section class="rounded-2xl border border-line bg-white p-5 shadow-card sm:p-6">
      <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-ocean"><Building2 :size="19" /></span><div><h2 class="font-semibold text-ink">Lokasi</h2><p class="text-xs text-muted">{{ sitePagination.total }} lokasi dalam cakupan akun</p></div></div>
      <form v-if="auth.can('sites.manage')" class="mt-5 grid gap-2 rounded-xl bg-canvas p-3 sm:grid-cols-2" @submit.prevent="createSite"><input v-model.trim="siteForm.name" required maxlength="160" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink placeholder:text-slate-500" placeholder="Nama lokasi" /><input v-model.trim="siteForm.code" required maxlength="64" pattern="[A-Za-z0-9_-]+" class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink placeholder:text-slate-500" placeholder="Kode lokasi" /><input v-model.trim="siteForm.timezone" required class="rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink placeholder:text-slate-500 sm:col-span-2" placeholder="Zona waktu (contoh Asia/Jakarta)" /><label class="text-xs font-semibold text-ink sm:col-span-2">Wi-Fi yang diizinkan <textarea v-model="siteForm.allowed_wifi_ssids" rows="3" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm font-normal text-ink placeholder:text-slate-500" placeholder="Satu nama Wi-Fi per baris; kosongkan bila tidak dibatasi" /></label><label class="text-xs font-semibold text-ink sm:col-span-2">Rentang IP publik yang diizinkan <textarea v-model="siteForm.allowed_ip_ranges" rows="3" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm font-normal text-ink placeholder:text-slate-500" placeholder="Contoh: 203.0.113.0/24 atau satu IP per baris" /></label><p class="text-xs leading-5 text-muted sm:col-span-2">SSID hanya dapat dilaporkan saat Android memberi izin lokasi dan mengungkap nama jaringan. IP yang dibandingkan adalah IP publik yang terlihat oleh Cloudflare; VPN atau carrier NAT dapat mengubah hasilnya.</p><button class="inline-flex items-center justify-center gap-2 rounded-lg bg-navy px-3 py-2 text-xs font-semibold text-white disabled:opacity-50 sm:col-span-2" :disabled="busy"><Plus :size="14" />Tambah lokasi dan kebijakan</button></form>
      <div v-if="loading" class="py-8 text-center text-sm text-muted">Memuat lokasi dari API…</div>
      <div v-else-if="!sites.length" class="py-8 text-center text-sm text-muted">Tidak ada lokasi yang dapat diakses.</div>
      <div v-else class="mt-4 divide-y divide-line"><div v-for="site in sites" :key="site.id" class="flex items-center justify-between gap-3 py-3"><div class="min-w-0"><p class="truncate text-sm font-semibold text-ink">{{ site.name }}</p><p class="mt-1 text-[11px] text-muted">{{ site.code }} · {{ site.timezone }} · {{ site.is_active ? 'Aktif' : 'Nonaktif' }}</p></div><button v-if="auth.can('sites.manage')" class="rounded-lg p-2 text-muted hover:bg-rose-50 hover:text-rose" :aria-label="`Hapus ${site.name}`" @click="removeSite(site)"><Trash2 :size="15" /></button></div></div>
      <div class="mt-4 flex items-center justify-between border-t border-line pt-3 text-xs text-muted"><span>Halaman {{ sitePagination.current_page }} / {{ sitePagination.last_page }}</span><div class="flex gap-2"><button class="rounded-md border border-line px-2.5 py-1.5 disabled:opacity-40" :disabled="sitePagination.current_page <= 1 || loading" @click="sitePagination.current_page--; loadSites()">Sebelumnya</button><button class="rounded-md border border-line px-2.5 py-1.5 disabled:opacity-40" :disabled="sitePagination.current_page >= sitePagination.last_page || loading" @click="sitePagination.current_page++; loadSites()">Berikutnya</button></div></div>
    </section>

    <section class="rounded-2xl border border-line bg-white p-5 shadow-card sm:p-6">
      <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-mint text-teal"><Users :size="19" /></span><div><h2 class="font-semibold text-ink">Tim</h2><p class="text-xs text-muted">{{ teamPagination.total }} tim dalam cakupan akun</p></div></div>
      <form v-if="auth.can('teams.manage')" class="mt-5 grid gap-2 rounded-xl bg-canvas p-3" @submit.prevent="createTeam"><select v-model="teamForm.site_id" required class="rounded-lg border border-line bg-white px-3 py-2 text-sm"><option value="">Pilih lokasi</option><option v-for="site in sites" :key="site.id" :value="String(site.id)">{{ site.name }}</option></select><input v-model.trim="teamForm.name" required maxlength="160" class="rounded-lg border border-line bg-white px-3 py-2 text-sm" placeholder="Nama tim" /><button class="inline-flex items-center justify-center gap-2 rounded-lg bg-navy px-3 py-2 text-xs font-semibold text-white disabled:opacity-50" :disabled="busy"><Plus :size="14" />Tambah tim</button></form>
      <label class="mt-4 block text-xs font-medium text-muted">Filter lokasi<select v-model="selectedSiteId" class="mt-1 w-full rounded-lg border border-line bg-white px-3 py-2 text-sm text-ink" @change="teamPagination.current_page = 1; loadTeams()"><option value="">Semua lokasi</option><option v-for="site in sites" :key="site.id" :value="String(site.id)">{{ site.name }}</option></select></label>
      <div v-if="loading" class="py-8 text-center text-sm text-muted">Memuat tim dari API…</div>
      <div v-else-if="!teams.length" class="py-8 text-center text-sm text-muted">Tidak ada tim di filter ini.</div>
      <div v-else class="mt-2 divide-y divide-line"><div v-for="team in teams" :key="team.id" class="flex items-center justify-between gap-3 py-3"><div class="min-w-0"><p class="truncate text-sm font-semibold text-ink">{{ team.name }}</p><p class="mt-1 text-[11px] text-muted">{{ team.site?.name ?? 'Lokasi' }} · {{ team.is_active ? 'Aktif' : 'Nonaktif' }}</p></div><button v-if="auth.can('teams.manage')" class="rounded-lg p-2 text-muted hover:bg-rose-50 hover:text-rose" :aria-label="`Hapus ${team.name}`" @click="removeTeam(team)"><Trash2 :size="15" /></button></div></div>
      <div class="mt-4 flex items-center justify-between border-t border-line pt-3 text-xs text-muted"><span>Halaman {{ teamPagination.current_page }} / {{ teamPagination.last_page }}</span><div class="flex gap-2"><button class="rounded-md border border-line px-2.5 py-1.5 disabled:opacity-40" :disabled="teamPagination.current_page <= 1 || loading" @click="teamPagination.current_page--; loadTeams()">Sebelumnya</button><button class="rounded-md border border-line px-2.5 py-1.5 disabled:opacity-40" :disabled="teamPagination.current_page >= teamPagination.last_page || loading" @click="teamPagination.current_page++; loadTeams()">Berikutnya</button></div></div>
    </section>
  </div>
  <p v-if="!canManage" class="mt-4 text-xs text-muted">Akun ini hanya memiliki akses baca; server tetap memeriksa setiap izin pada operasi perubahan.</p>
</template>
