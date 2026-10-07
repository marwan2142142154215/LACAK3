<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { Archive, ArrowLeft, Camera, LockKeyhole, MapPin, Pencil, RefreshCw, Save, UnlockKeyhole } from '@lucide/vue'
import dayjs from 'dayjs'
import 'dayjs/locale/id'
import { useRouter } from 'vue-router'
import PageHeader from '../components/PageHeader.vue'
import StatusPill from '../components/StatusPill.vue'
import { api, apiMessage, idempotencyKey, type ApiEnvelope, type Paginated } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import type { Device, DeviceCommand, DeviceLocation, Site, Team } from '../types/platform'

dayjs.locale('id')
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const device = ref<Device | null>(null)
const commands = ref<DeviceCommand[]>([])
const locations = ref<DeviceLocation[]>([])
const loading = ref(true)
const busy = ref('')
const error = ref('')
const commandNotice = ref('')
const editOpen = ref(false)
const editBusy = ref(false)
const editError = ref('')
const editSites = ref<Site[]>([])
const editTeams = ref<Team[]>([])
const editForm = ref({ name: '', manufacturer: '', model: '', site_id: '', team_id: '' })
let refreshTimer: number | undefined

async function loadDetail() {
  loading.value = true
  error.value = ''
  try {
    const deviceId = String(route.params.deviceId)
    const [deviceResponse, commandResponse, locationResponse] = await Promise.all([
      api.get<ApiEnvelope<Device>>(`/api/v1/devices/${deviceId}`),
      api.get<ApiEnvelope<Paginated<DeviceCommand>>>(`/api/v1/devices/${deviceId}/commands`, { params: { per_page: 10 } }),
      auth.can('devices.location')
        ? api.get<ApiEnvelope<Paginated<DeviceLocation>>>(`/api/v1/devices/${deviceId}/locations`, { params: { per_page: 10 } })
        : Promise.resolve(null),
    ])
    device.value = deviceResponse.data.data
    commands.value = commandResponse.data.data.items
    locations.value = locationResponse?.data.data.items ?? []
  } catch (loadError) {
    error.value = apiMessage(loadError)
    device.value = null
  } finally {
    loading.value = false
  }
}

async function sendCommand(commandType: 'LOCK' | 'UNLOCK' | 'REQUEST_LOCATION') {
  if (!device.value) return
  const confirmation = commandType === 'LOCK'
    ? 'Kirim perintah kunci ke perangkat ini? Perangkat harus terkelola dan online agar dapat menjalankannya.'
    : commandType === 'UNLOCK'
      ? 'Kirim perintah buka kunci ke perangkat ini?'
      : 'Minta lokasi terakhir yang diizinkan dari perangkat ini?'
  if (!window.confirm(confirmation)) return
  busy.value = commandType
  commandNotice.value = ''
  try {
    const response = await api.post<ApiEnvelope<DeviceCommand>>(`/api/v1/devices/${device.value.id}/commands`, {
      command_type: commandType,
      ...(commandType === 'REQUEST_LOCATION' ? { payload: { priority: 'BALANCED', max_age_seconds: 900 } } : {}),
    }, { headers: { 'Idempotency-Key': idempotencyKey() } })
    commandNotice.value = `${response.data.message} Status awal: ${response.data.data.status}. Keberhasilan baru tercatat setelah acknowledgement perangkat.`
    await loadDetail()
  } catch (requestError) {
    commandNotice.value = apiMessage(requestError)
  } finally {
    busy.value = ''
  }
}

async function beginEdit() {
  if (!device.value || !auth.can('devices.update')) return
  editBusy.value = true
  editError.value = ''
  try {
    const [siteResponse, teamResponse] = await Promise.all([
      api.get<ApiEnvelope<Paginated<Site>>>('/api/v1/sites', { params: { per_page: 100 } }),
      api.get<ApiEnvelope<Paginated<Team>>>('/api/v1/teams', { params: { site_id: device.value.site_id, per_page: 100 } }),
    ])
    editSites.value = siteResponse.data.data.items
    editTeams.value = teamResponse.data.data.items
    editForm.value = {
      name: device.value.name,
      manufacturer: device.value.manufacturer ?? '',
      model: device.value.model ?? '',
      site_id: String(device.value.site_id),
      team_id: String(device.value.team_id),
    }
    editOpen.value = true
  } catch (requestError) {
    editError.value = apiMessage(requestError)
  } finally {
    editBusy.value = false
  }
}

async function changeEditSite() {
  editForm.value.team_id = ''
  editError.value = ''
  try {
    const response = await api.get<ApiEnvelope<Paginated<Team>>>('/api/v1/teams', { params: { site_id: Number(editForm.value.site_id), per_page: 100 } })
    editTeams.value = response.data.data.items
  } catch (requestError) {
    editError.value = apiMessage(requestError)
  }
}

async function saveDevice() {
  if (!device.value || !auth.can('devices.update')) return
  editBusy.value = true
  editError.value = ''
  try {
    await api.patch(`/api/v1/devices/${device.value.id}`, {
      name: editForm.value.name.trim(),
      manufacturer: editForm.value.manufacturer.trim() || null,
      model: editForm.value.model.trim() || null,
      site_id: Number(editForm.value.site_id),
      team_id: Number(editForm.value.team_id),
    })
    editOpen.value = false
    commandNotice.value = 'Detail perangkat berhasil diperbarui.'
    await loadDetail()
  } catch (requestError) {
    editError.value = apiMessage(requestError)
  } finally {
    editBusy.value = false
  }
}

async function archiveDevice() {
  if (!device.value || !auth.can('devices.delete')) return
  if (!window.confirm(`Arsipkan ${device.value.name}? Kredensial/sesi akan dicabut dan command yang belum selesai dibatalkan.`)) return
  busy.value = 'ARCHIVE'
  error.value = ''
  try {
    await api.delete(`/api/v1/devices/${device.value.id}`)
    await router.replace('/devices')
  } catch (requestError) {
    error.value = apiMessage(requestError)
  } finally {
    busy.value = ''
  }
}

onMounted(() => {
  void loadDetail()
  refreshTimer = window.setInterval(() => void loadDetail(), 15_000)
})
onUnmounted(() => { if (refreshTimer) window.clearInterval(refreshTimer) })
</script>

<template>
  <PageHeader eyebrow="Perangkat" :title="device?.name ?? 'Detail perangkat'" description="Telemetri, lokasi terakhir yang diterima server, dan status command terkonfirmasi dari device agent.">
    <RouterLink to="/devices" class="inline-flex items-center gap-2 rounded-xl border border-line bg-white px-4 py-2.5 text-sm font-semibold text-ink"><ArrowLeft :size="15" /> Kembali</RouterLink>
    <button class="inline-flex items-center gap-2 rounded-xl border border-line bg-white px-4 py-2.5 text-sm font-semibold text-ink" :disabled="loading" @click="loadDetail"><RefreshCw :size="15" :class="loading ? 'animate-spin' : ''" /> Perbarui</button>
    <button v-if="auth.can('devices.update')" class="inline-flex items-center gap-2 rounded-xl border border-line bg-white px-4 py-2.5 text-sm font-semibold text-ink" :disabled="editBusy" @click="beginEdit"><Pencil :size="15" /> Ubah perangkat</button>
    <button v-if="auth.can('devices.delete')" class="inline-flex items-center gap-2 rounded-xl border border-rose-200 bg-white px-4 py-2.5 text-sm font-semibold text-rose disabled:opacity-50" :disabled="!!busy" @click="archiveDevice"><Archive :size="15" /> {{ busy === 'ARCHIVE' ? 'Mengarsipkan…' : 'Arsipkan' }}</button>
  </PageHeader>

  <div v-if="error" role="alert" class="mb-5 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ error }}</div>
  <div v-if="commandNotice" role="status" class="mb-5 rounded-xl border border-teal/20 bg-teal/5 px-4 py-3 text-sm text-ink">{{ commandNotice }}</div>
  <div v-if="device" class="grid gap-5 xl:grid-cols-[0.9fr_1.1fr]">
    <div class="space-y-5">
      <section class="rounded-2xl border border-line bg-white p-5 shadow-card sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3"><div><p class="text-xs font-semibold uppercase tracking-[0.14em] text-teal">Kesehatan perangkat</p><div class="mt-2"><StatusPill :status="device.status" /></div></div><div class="flex flex-wrap gap-2">
          <button v-if="auth.can('devices.lock')" class="inline-flex items-center gap-2 rounded-xl bg-navy px-3 py-2 text-xs font-semibold text-white disabled:opacity-50" :disabled="!!busy" @click="sendCommand('LOCK')"><LockKeyhole :size="14" />{{ busy === 'LOCK' ? 'Mengirim…' : 'Kunci' }}</button>
          <button v-if="auth.can('devices.unlock')" class="inline-flex items-center gap-2 rounded-xl border border-line px-3 py-2 text-xs font-semibold text-ink disabled:opacity-50" :disabled="!!busy" @click="sendCommand('UNLOCK')"><UnlockKeyhole :size="14" />{{ busy === 'UNLOCK' ? 'Mengirim…' : 'Buka kunci' }}</button>
          <button v-if="auth.can('devices.location')" class="inline-flex items-center gap-2 rounded-xl border border-line px-3 py-2 text-xs font-semibold text-ink disabled:opacity-50" :disabled="!!busy" @click="sendCommand('REQUEST_LOCATION')"><MapPin :size="14" />{{ busy === 'REQUEST_LOCATION' ? 'Meminta…' : 'Minta lokasi' }}</button>
        </div></div>
        <p v-if="device.lock_state === 'LOCKED' && !device.device_owner" class="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-900">Perangkat ini belum melaporkan Device Owner. Perintah lock dapat gagal karena Android tidak memberikan capability pengelolaan yang diperlukan.</p>
        <dl class="mt-5 grid grid-cols-2 gap-x-4 gap-y-5 border-t border-line pt-5 sm:grid-cols-3">
          <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-muted">Lokasi / tim</dt><dd class="mt-1 text-sm font-medium text-ink">{{ device.site?.name ?? '—' }} / {{ device.team?.name ?? '—' }}</dd></div>
          <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-muted">Kunci perangkat</dt><dd class="mt-1"><StatusPill :status="device.lock_state" /></dd></div>
          <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-muted">Baterai</dt><dd class="mt-1 text-sm font-medium text-ink">{{ device.battery_percent === null ? 'Tidak diketahui' : `${device.battery_percent}%` }}</dd></div>
          <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-muted">Android</dt><dd class="mt-1 text-sm font-medium text-ink">{{ device.android_version ?? 'Tidak diketahui' }} <span v-if="device.android_api" class="text-xs text-muted">(API {{ device.android_api }})</span></dd></div>
          <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-muted">App / model</dt><dd class="mt-1 text-sm font-medium text-ink">{{ device.app_version ?? 'App belum melapor' }} · {{ device.manufacturer ?? '' }} {{ device.model ?? '' }}</dd></div>
          <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-muted">Heartbeat</dt><dd class="mt-1 text-sm font-medium text-ink">{{ device.last_seen_at ? dayjs(device.last_seen_at).format('D MMM YYYY, HH:mm:ss') : 'Belum pernah diterima' }}</dd></div>
          <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-muted">WebSocket</dt><dd class="mt-1"><StatusPill :status="device.connection_state" /></dd></div>
          <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-muted">Jaringan</dt><dd class="mt-1 text-sm font-medium text-ink">{{ device.network_type ?? 'Tidak diketahui' }}</dd></div>
          <div><dt class="text-[10px] font-semibold uppercase tracking-wide text-muted">Managed device</dt><dd class="mt-1 text-sm font-medium text-ink">{{ device.device_owner ? 'Device Owner' : device.managed_device ? 'Managed' : 'Tidak terkelola' }}</dd></div>
        </dl>
        <div class="mt-5 border-t border-line pt-4"><p class="text-xs font-semibold text-ink">Capability dilaporkan agent</p><div class="mt-2 flex flex-wrap gap-2"><span v-for="(available, key) in device.capabilities" :key="key" class="rounded-lg px-2.5 py-1 text-[10px] font-medium" :class="available ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'">{{ key }}: {{ available ? 'tersedia' : 'tidak tersedia' }}</span></div></div>
      </section>

      <section class="rounded-2xl border border-line bg-white p-5 shadow-card sm:p-6">
        <div class="flex items-center justify-between"><div><h2 class="text-base font-semibold text-ink">Lokasi terakhir diterima</h2><p class="mt-1 text-xs text-muted">Koordinat tidak disimulasikan. Waktu, sumber, dan akurasi berasal dari rekaman server.</p></div><MapPin :size="18" class="text-teal" /></div>
        <div v-if="device.latest_location" class="mt-4 rounded-xl bg-canvas p-4"><p class="font-mono text-sm font-semibold text-ink">{{ Number(device.latest_location.latitude).toFixed(6) }}, {{ Number(device.latest_location.longitude).toFixed(6) }}</p><p class="mt-2 text-xs text-muted">{{ dayjs(device.latest_location.recorded_at).format('D MMM YYYY, HH:mm:ss') }} · {{ device.latest_location.source }} · akurasi {{ device.latest_location.accuracy_meters ?? '—' }} m</p><a class="mt-3 inline-flex text-xs font-semibold text-ocean underline" :href="`https://www.openstreetmap.org/?mlat=${device.latest_location.latitude}&mlon=${device.latest_location.longitude}#map=16/${device.latest_location.latitude}/${device.latest_location.longitude}`" target="_blank" rel="noreferrer">Lihat di OpenStreetMap</a></div>
        <p v-else class="mt-4 rounded-xl bg-canvas px-4 py-5 text-sm text-muted">Belum ada lokasi yang diterima. Periksa izin lokasi dan kondisi jaringan pada device.</p>
        <p v-if="auth.can('devices.camera')" class="mt-4 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 text-xs leading-5 text-amber-900"><Camera :size="15" class="mt-0.5 shrink-0" />Remote camera belum didukung oleh Android agent saat ini; permintaan tidak dikirim dan tidak akan ditandai berhasil. Kamera background dibatasi Android dan memerlukan implementasi lifecycle/capability yang sah.</p>
      </section>
    </div>

    <div class="space-y-5">
      <section class="rounded-2xl border border-line bg-white p-5 shadow-card sm:p-6">
        <div class="flex items-center justify-between"><div><h2 class="text-base font-semibold text-ink">Riwayat command</h2><p class="mt-1 text-xs text-muted">Status hanya berubah setelah gateway/device mengirim acknowledgement.</p></div><span class="rounded-lg bg-canvas px-2 py-1 text-[10px] font-medium text-muted">10 terakhir</span></div>
        <div v-if="!commands.length" class="mt-4 rounded-xl bg-canvas px-4 py-8 text-center text-sm text-muted">Belum ada command.</div>
        <div v-else class="mt-4 overflow-x-auto"><table class="w-full min-w-[520px] text-left"><thead><tr class="border-b border-line text-[10px] uppercase tracking-wide text-muted"><th class="py-2 pr-3">Command</th><th class="py-2 pr-3">Status</th><th class="py-2 pr-3">Dibuat</th><th class="py-2">Hasil</th></tr></thead><tbody><tr v-for="command in commands" :key="command.command_id" class="border-b border-line last:border-0"><td class="py-3 pr-3"><p class="text-xs font-semibold text-ink">{{ command.command_type }}</p><p class="mt-1 font-mono text-[10px] text-muted">{{ command.command_id }}</p></td><td class="py-3 pr-3"><StatusPill :status="command.status" /></td><td class="py-3 pr-3 text-xs text-muted">{{ dayjs(command.created_at).format('D MMM, HH:mm') }}</td><td class="max-w-[160px] py-3 text-xs text-muted">{{ command.result_message ?? command.result_code ?? 'Menunggu acknowledgement' }}</td></tr></tbody></table></div>
      </section>
      <section class="rounded-2xl border border-line bg-white p-5 shadow-card sm:p-6">
        <div class="flex items-center justify-between"><div><h2 class="text-base font-semibold text-ink">Riwayat lokasi</h2><p class="mt-1 text-xs text-muted">{{ locations.length }} rekaman terbaru di halaman ini</p></div><MapPin :size="17" class="text-teal" /></div>
        <div v-if="!locations.length" class="mt-4 rounded-xl bg-canvas px-4 py-7 text-center text-sm text-muted">Belum ada lokasi tersimpan.</div>
        <div v-else class="mt-4 divide-y divide-line"><div v-for="location in locations" :key="location.id" class="flex items-center justify-between gap-4 py-3"><div><p class="font-mono text-xs font-semibold text-ink">{{ Number(location.latitude).toFixed(5) }}, {{ Number(location.longitude).toFixed(5) }}</p><p class="mt-1 text-[10px] text-muted">{{ location.source }} · ±{{ location.accuracy_meters ?? '—' }} m</p></div><time class="shrink-0 text-[10px] text-muted">{{ dayjs(location.recorded_at).format('D MMM, HH:mm') }}</time></div></div>
      </section>
    </div>
  </div>
  <div v-else-if="loading" class="rounded-2xl border border-line bg-white px-5 py-14 text-center text-sm text-muted">Memuat data perangkat dari API…</div>

  <div v-if="editOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-navy/45 p-4" @click.self="editOpen = false">
    <form class="w-full max-w-xl rounded-2xl border border-line bg-white p-6 shadow-soft" @submit.prevent="saveDevice">
      <p class="text-xs font-semibold uppercase tracking-[0.14em] text-teal">Administrasi perangkat</p><h2 class="mt-1 text-xl font-semibold text-ink">Ubah detail perangkat</h2>
      <div class="mt-5 grid gap-4 sm:grid-cols-2">
        <label class="text-xs font-semibold text-ink sm:col-span-2">Nama<input v-model.trim="editForm.name" required maxlength="160" class="mt-1.5 w-full rounded-xl border border-line px-3 py-2.5 text-sm font-normal" /></label>
        <label class="text-xs font-semibold text-ink">Pabrikan<input v-model.trim="editForm.manufacturer" maxlength="120" class="mt-1.5 w-full rounded-xl border border-line px-3 py-2.5 text-sm font-normal" /></label>
        <label class="text-xs font-semibold text-ink">Model<input v-model.trim="editForm.model" maxlength="160" class="mt-1.5 w-full rounded-xl border border-line px-3 py-2.5 text-sm font-normal" /></label>
        <label class="text-xs font-semibold text-ink">Lokasi<select v-model="editForm.site_id" required class="mt-1.5 w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-normal" @change="changeEditSite"><option v-for="site in editSites" :key="site.id" :value="String(site.id)">{{ site.name }}</option></select></label>
        <label class="text-xs font-semibold text-ink">Tim<select v-model="editForm.team_id" required class="mt-1.5 w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm font-normal"><option v-for="team in editTeams" :key="team.id" :value="String(team.id)">{{ team.name }}</option></select></label>
      </div>
      <p v-if="editError" role="alert" class="mt-4 rounded-xl bg-rose-50 px-3 py-2.5 text-xs text-rose">{{ editError }}</p>
      <div class="mt-6 flex justify-end gap-2"><button type="button" class="rounded-xl border border-line px-4 py-2.5 text-sm font-medium text-muted" @click="editOpen = false">Batal</button><button class="inline-flex items-center gap-2 rounded-xl bg-navy px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50" :disabled="editBusy"><Save :size="14" />{{ editBusy ? 'Menyimpan…' : 'Simpan' }}</button></div>
    </form>
  </div>
</template>
