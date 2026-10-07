<script setup lang="ts">
import type { DeviceStatus } from '../types/platform'

defineProps<{ status: DeviceStatus | string }>()

const labels: Record<string, string> = {
  ONLINE: 'Online', DEGRADED: 'Degraded', OFFLINE: 'Offline', UNKNOWN: 'Unknown',
  LOCKED: 'Terkunci', UNLOCKED: 'Tidak terkunci', CONNECTED: 'Terhubung', DISCONNECTED: 'Terputus',
}
</script>

<template>
  <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold" :class="{
    'bg-emerald-50 text-emerald-700': ['ONLINE', 'CONNECTED', 'UNLOCKED'].includes(status),
    'bg-amber-50 text-amber-700': ['DEGRADED', 'UNKNOWN'].includes(status),
    'bg-slate-100 text-slate-600': ['OFFLINE', 'DISCONNECTED'].includes(status),
    'bg-rose-50 text-rose-700': status === 'LOCKED',
  }">
    <span class="h-1.5 w-1.5 rounded-full" :class="{
      'bg-emerald-500': ['ONLINE', 'CONNECTED', 'UNLOCKED'].includes(status),
      'bg-amber-500': ['DEGRADED', 'UNKNOWN'].includes(status),
      'bg-slate-400': ['OFFLINE', 'DISCONNECTED'].includes(status),
      'bg-rose-500': status === 'LOCKED',
    }" />
    {{ labels[status] ?? status }}
  </span>
</template>
