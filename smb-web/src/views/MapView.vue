<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { LMap, LMarker, LPopup, LTileLayer } from '@vue-leaflet/vue-leaflet'
import L from 'leaflet'
import dayjs from 'dayjs'
import 'dayjs/locale/id'
import PageHeader from '../components/PageHeader.vue'
import StatusPill from '../components/StatusPill.vue'
import { api, apiMessage, type ApiEnvelope, type Paginated } from '../lib/api'
import type { Device } from '../types/platform'

dayjs.locale('id')
const devices = ref<Device[]>([])
const loading = ref(true)
const error = ref('')
const total = ref(0)
const zoom = ref(2)
const center: [number, number] = [0, 0]
function markerIcon(color: string): L.Icon {
  return L.divIcon({ className: 'device-marker', html: `<span style="background:${color}"></span>`, iconSize: [18, 18], iconAnchor: [9, 9] }) as unknown as L.Icon
}
const markerIcons: Record<string, L.Icon> = {
  ONLINE: markerIcon('#168477'), DEGRADED: markerIcon('#e5a239'), OFFLINE: markerIcon('#8795a8'), UNKNOWN: markerIcon('#c6d0dc'),
}
const locatedDevices = computed(() => devices.value.filter((device) => device.latest_location && Number.isFinite(Number(device.latest_location.latitude)) && Number.isFinite(Number(device.latest_location.longitude))))

async function loadAllDevices() {
  loading.value = true
  error.value = ''
  try {
    const collected: Device[] = []
    let page = 1
    let lastPage = 1
    do {
      const response = await api.get<ApiEnvelope<Paginated<Device>>>('/api/v1/devices', { params: { page, per_page: 100 } })
      collected.push(...response.data.data.items)
      total.value = response.data.data.pagination.total
      lastPage = response.data.data.pagination.last_page
      page += 1
    } while (page <= lastPage)
    devices.value = collected
  } catch (loadError) {
    error.value = apiMessage(loadError)
    devices.value = []
  } finally {
    loading.value = false
  }
}

onMounted(loadAllDevices)
</script>

<template>
  <PageHeader eyebrow="Peta" title="Lokasi perangkat" description="Peta menggunakan hanya lokasi yang benar-benar tersimpan di server dan dapat diakses akun ini.">
    <button class="inline-flex items-center gap-2 rounded-xl border border-line bg-white px-4 py-2.5 text-sm font-semibold text-ink" :disabled="loading" @click="loadAllDevices">Perbarui data</button>
  </PageHeader>
  <div v-if="error" role="alert" class="mb-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ error }}</div>
  <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-line bg-white px-4 py-3"><p class="text-sm font-medium text-ink">{{ locatedDevices.length }} dari {{ total }} perangkat memiliki lokasi tersimpan.</p><div class="flex flex-wrap gap-3"><div v-for="status in ['ONLINE', 'DEGRADED', 'OFFLINE', 'UNKNOWN']" :key="status" class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" :class="{ 'bg-teal': status === 'ONLINE', 'bg-amber-400': status === 'DEGRADED', 'bg-slate-400': status === 'OFFLINE', 'bg-slate-300': status === 'UNKNOWN' }" /><span class="text-[10px] text-muted">{{ status }}</span></div></div></div>
  <section class="relative overflow-hidden rounded-2xl border border-line bg-white shadow-card">
    <div v-if="loading" class="absolute inset-0 z-20 grid place-items-center bg-white/90 text-sm font-medium text-muted">Memuat lokasi authorized dari server…</div>
    <div v-if="!loading && !locatedDevices.length" class="absolute left-1/2 top-5 z-10 -translate-x-1/2 rounded-xl border border-line bg-white px-4 py-3 text-center shadow-card"><p class="text-sm font-semibold text-ink">Belum ada lokasi tersimpan</p><p class="mt-1 text-xs text-muted">Data akan tampil setelah device mengirim lokasi dengan izin yang sesuai.</p></div>
    <LMap v-model:zoom="zoom" :center="center" :use-global-leaflet="false" class="h-[620px] w-full">
      <LTileLayer url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" layer-type="base" name="OpenStreetMap" attribution="&copy; OpenStreetMap contributors" />
      <LMarker v-for="device in locatedDevices" :key="device.id" :lat-lng="[Number(device.latest_location!.latitude), Number(device.latest_location!.longitude)]" :icon="markerIcons[device.status]">
        <LPopup><div class="min-w-[200px]"><p class="font-semibold">{{ device.name }}</p><p class="my-1 text-xs">{{ device.site?.name ?? 'Lokasi tidak diketahui' }} · {{ device.team?.name ?? 'Tanpa tim' }}</p><StatusPill :status="device.status" /><p class="mt-2 text-xs">{{ dayjs(device.latest_location!.recorded_at).format('D MMM YYYY, HH:mm:ss') }}</p><p class="mt-1 text-xs">Akurasi ±{{ device.latest_location!.accuracy_meters ?? '—' }} m · {{ device.latest_location!.source }}</p><a class="mt-2 inline-block text-xs text-blue-700 underline" :href="`https://www.openstreetmap.org/?mlat=${device.latest_location!.latitude}&mlon=${device.latest_location!.longitude}#map=16/${device.latest_location!.latitude}/${device.latest_location!.longitude}`" target="_blank" rel="noreferrer">Buka detail peta</a></div></LPopup>
      </LMarker>
    </LMap>
  </section>
  <p class="mt-3 text-[11px] leading-5 text-muted">Peta dasar berasal dari OpenStreetMap. Marker menunjukkan titik dengan timestamp terakhir yang direkam server; titik dapat kedaluwarsa dan tidak menyatakan posisi langsung.</p>
</template>

<style>
.device-marker { background: transparent; border: 0; }
.device-marker span { display: block; width: 18px; height: 18px; border: 3px solid white; border-radius: 50%; box-shadow: 0 2px 8px #0f172a66; }
</style>
