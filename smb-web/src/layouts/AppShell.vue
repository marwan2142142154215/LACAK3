<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import {
  Activity, Building2, ChevronDown, LayoutDashboard, MapPinned, Users, ShieldAlert, Download,
  ClipboardList, Menu, MessageCircle, RefreshCw, ShieldCheck, Smartphone, X,
} from '@lucide/vue'
import { api, apiMessage, type ApiEnvelope } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import type { ServiceHealth } from '../types/platform'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const menuOpen = ref(false)
const mobileOpen = ref(false)
const health = ref<ServiceHealth | null>(null)
const healthError = ref('')
const healthBusy = ref(false)
let healthTimer: number | undefined

const navItems = [
  { label: 'Ringkasan', to: '/', icon: LayoutDashboard },
  { label: 'Perangkat', to: '/devices', icon: Smartphone, permission: 'devices.view' },
  { label: 'Peta perangkat', to: '/map', icon: MapPinned, permission: 'devices.view' },
  { label: 'Lokasi & tim', to: '/sites-teams', icon: Building2, permission: 'sites.view' },
  { label: 'Pelanggaran jaringan', to: '/network-violations', icon: ShieldAlert, permission: 'sites.view' },
  { label: 'Pusat unduhan', to: '/downloads', icon: Download, permission: 'devices.view' },
  { label: 'Sesi aktif', to: '/sessions', icon: ShieldCheck },
  { label: 'Pengguna', to: '/users', icon: Users, permission: 'users.manage' },
  { label: 'Akses Telegram', to: '/telegram', icon: MessageCircle, permission: 'telegram.manage' },
  { label: 'Audit aktivitas', to: '/audit-logs', icon: ClipboardList, permission: 'audit.view' },
]
const visibleNav = computed(() => navItems.filter((item) => !item.permission || auth.can(item.permission)))
const title = computed(() => String(route.meta.title ?? 'Ringkasan'))
const roleLabels: Record<string, string> = { SUPER_ADMIN: 'Super admin', ADMIN: 'Administrator', OPERATOR: 'Operator', VIEWER: 'Viewer' }
const roleLabel = computed(() => roleLabels[auth.user?.roles[0] ?? ''] ?? auth.user?.roles[0] ?? 'Pengguna')
const degradedServices = computed(() => Object.entries(health.value?.services ?? {}).filter(([, service]) => service.status !== 'OK'))

async function loadHealth() {
  healthBusy.value = true
  try {
    const response = await api.get<ApiEnvelope<ServiceHealth>>('/api/v1/health')
    health.value = response.data.data
    healthError.value = ''
  } catch (error) {
    healthError.value = apiMessage(error)
  } finally {
    healthBusy.value = false
  }
}

async function logout() {
  menuOpen.value = false
  await auth.logout().catch(() => undefined)
  await router.replace({ name: 'login' })
}

onMounted(() => {
  void loadHealth()
  healthTimer = window.setInterval(() => void loadHealth(), 60_000)
})
onUnmounted(() => { if (healthTimer) window.clearInterval(healthTimer) })
</script>

<template>
  <div class="min-h-screen bg-[#f3f6fb] text-ink">
    <aside class="fixed inset-y-0 left-0 z-40 hidden w-[264px] flex-col border-r border-white/10 bg-gradient-to-b from-[#102541] to-[#0b1b30] text-white lg:flex">
      <div class="flex h-[82px] items-center gap-3 border-b border-white/10 px-6">
        <div class="grid h-11 w-11 place-items-center rounded-2xl border border-teal-200/20 bg-teal-300/10 text-teal-200 shadow-inner"><ShieldCheck :size="23" /></div>
        <div><p class="text-base font-bold tracking-[0.12em]">SMB</p><p class="mt-0.5 text-xs font-medium text-slate-300">Device Operations</p></div>
      </div>
      <div class="px-4 pt-6">
        <p class="px-3 text-[11px] font-bold uppercase tracking-[0.16em] text-slate-300">Workspace</p>
        <nav class="mt-3 space-y-1.5">
          <RouterLink v-for="item in visibleNav" :key="item.to" :to="item.to" class="group flex items-center gap-3 rounded-xl border border-transparent px-3 py-3 text-sm font-medium text-slate-200 transition hover:border-white/10 hover:bg-white/[0.07] hover:text-white" :class="route.path === item.to || (item.to === '/devices' && route.path.startsWith('/devices/')) ? 'border-teal-200/15 bg-teal-300/10 text-white shadow-inner' : ''">
            <component :is="item.icon" :size="18" :stroke-width="1.9" class="text-slate-300 transition group-hover:text-teal-200" />
            <span>{{ item.label }}</span>
            <span v-if="item.to === '/devices'" class="ml-auto rounded-md border border-teal-200/20 bg-teal-300/10 px-2 py-0.5 text-[10px] font-bold tracking-wide text-teal-100">LIVE</span>
          </RouterLink>
        </nav>
      </div>
      <div class="mt-auto p-4">
        <div class="rounded-2xl border border-white/10 bg-white/[0.07] p-4 shadow-lg shadow-black/10">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-2 text-sm font-semibold text-slate-100"><Activity :size="16" class="text-teal-200" /> Kesehatan layanan</div>
            <button class="rounded-md p-1 text-slate-400 hover:bg-white/10 hover:text-white" aria-label="Perbarui health" :disabled="healthBusy" @click="loadHealth"><RefreshCw :size="13" :class="healthBusy ? 'animate-spin' : ''" /></button>
          </div>
          <p class="mt-2 flex items-center gap-2 text-sm font-semibold" :class="health?.status === 'READY' ? 'text-emerald-300' : 'text-amber-300'">
            <span class="h-2 w-2 rounded-full" :class="health?.status === 'READY' ? 'bg-emerald-400' : 'bg-amber-400'" />
            {{ healthError ? 'Tidak tersedia' : health?.status === 'READY' ? 'System ready' : health ? 'Degraded' : 'Memeriksa…' }}
          </p>
          <p class="mt-1.5 text-xs leading-5 text-slate-300">{{ healthError ? healthError : degradedServices.length ? `${degradedServices.length} service belum siap atau belum dikonfigurasi.` : 'Semua service yang dipantau sehat.' }}</p>
          <div v-if="degradedServices.length" class="mt-3 space-y-1 border-t border-white/10 pt-2">
            <div v-for="[name, service] in degradedServices.slice(0, 3)" :key="name" class="flex justify-between gap-2 text-xs text-slate-200"><span>{{ name }}</span><span class="font-semibold text-amber-200">{{ service.status }}</span></div>
          </div>
        </div>
        <div class="mt-4 flex items-center gap-3 border-t border-white/10 px-2 pt-4">
          <div class="grid h-10 w-10 shrink-0 place-items-center rounded-full border border-teal-100/20 bg-teal-300/15 text-sm font-bold text-teal-100">{{ auth.user?.name.slice(0, 1).toUpperCase() }}</div>
          <div class="min-w-0 flex-1"><p class="truncate text-sm font-semibold text-white">{{ auth.user?.name }}</p><p class="mt-0.5 text-xs text-slate-300">{{ roleLabel }}</p></div>
          <button class="rounded-lg p-2 text-slate-400 hover:bg-white/10 hover:text-white" aria-label="Keluar" @click="logout"><ChevronDown :size="15" class="rotate-[-90deg]" /></button>
        </div>
      </div>
    </aside>

    <div v-if="mobileOpen" class="fixed inset-0 z-50 bg-gradient-to-b from-[#102541] to-[#0b1b30] text-white lg:hidden">
      <div class="flex h-[68px] items-center justify-between border-b border-white/10 px-5">
        <div class="flex items-center gap-3"><div class="grid h-9 w-9 place-items-center rounded-xl bg-white/10 text-teal-300"><ShieldCheck :size="19" /></div><span class="font-semibold">SMB</span></div>
        <button aria-label="Tutup navigasi" class="rounded-lg p-2 text-slate-300" @click="mobileOpen = false"><X :size="20" /></button>
      </div>
      <nav class="space-y-1 p-4">
        <RouterLink v-for="item in visibleNav" :key="item.to" :to="item.to" class="flex items-center gap-3 rounded-xl px-3 py-3 text-sm text-slate-200" @click="mobileOpen = false"><component :is="item.icon" :size="18" /> {{ item.label }}</RouterLink>
        <button class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-sm text-slate-200" @click="logout"><ChevronDown :size="18" class="rotate-[-90deg]" /> Keluar</button>
      </nav>
    </div>

    <div class="lg:pl-[264px]">
      <header class="sticky top-0 z-30 flex h-[72px] items-center justify-between border-b border-[#e4eaf2] bg-white/90 px-4 shadow-[0_4px_24px_rgba(16,37,65,0.035)] backdrop-blur-xl sm:px-7 lg:h-[82px] lg:px-10">
        <div class="flex items-center gap-3">
          <button class="rounded-lg p-2 text-muted hover:bg-canvas lg:hidden" aria-label="Buka navigasi" @click="mobileOpen = true"><Menu :size="20" /></button>
          <div><p class="text-xs font-semibold text-ocean">SMB <span class="mx-1 text-slate-300">/</span> <span class="text-ink">{{ title }}</span></p><p class="mt-1 hidden text-xs text-muted sm:block">Perangkat terdaftar dan berada dalam cakupan akses Anda</p></div>
        </div>
        <div class="flex items-center gap-3">
          <div class="hidden items-center gap-2 rounded-full border px-3.5 py-2 text-xs font-semibold sm:flex" :class="health?.status === 'READY' ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-amber-200 bg-amber-50 text-amber-900'"><span class="h-2 w-2 rounded-full" :class="health?.status === 'READY' ? 'bg-emerald-500' : 'bg-amber-500'" />{{ health?.status === 'READY' ? 'Semua layanan siap' : 'Periksa kesehatan layanan' }}</div>
          <div class="relative">
            <button class="flex items-center gap-2 rounded-xl p-1.5 pr-2 text-left hover:bg-canvas" @click="menuOpen = !menuOpen">
              <div class="grid h-9 w-9 place-items-center rounded-full bg-gradient-to-br from-[#d9f3ef] to-[#dceafa] text-sm font-bold text-ocean ring-1 ring-ocean/10">{{ auth.user?.name.slice(0, 1).toUpperCase() }}</div>
              <span class="hidden max-w-40 truncate text-sm font-semibold text-ink md:block">{{ auth.user?.name }}</span>
              <ChevronDown :size="14" class="text-muted" />
            </button>
            <div v-if="menuOpen" class="absolute right-0 top-11 w-52 rounded-xl border border-line bg-white p-2 shadow-soft">
              <div class="border-b border-line px-3 py-2"><p class="truncate text-xs font-semibold text-ink">{{ auth.user?.name }}</p><p class="truncate text-[11px] text-muted">{{ auth.user?.username }}</p></div>
              <RouterLink to="/sessions" class="flex items-center gap-2 rounded-lg px-3 py-2.5 text-xs text-ink hover:bg-canvas" @click="menuOpen = false"><ShieldCheck :size="15" /> Sesi & keamanan</RouterLink>
              <button class="flex w-full items-center gap-2 rounded-lg px-3 py-2.5 text-xs text-rose hover:bg-rose/5" @click="logout"><ChevronDown :size="15" class="rotate-[-90deg]" /> Keluar dari SMB</button>
            </div>
          </div>
        </div>
      </header>
      <main class="app-workspace mx-auto min-h-[calc(100vh-140px)] max-w-[1600px] px-4 py-6 sm:px-7 sm:py-8 lg:px-10">
        <RouterView />
      </main>
      <footer class="border-t border-line px-4 py-4 text-center text-[11px] text-muted sm:px-7 lg:px-10">SMB · Device Management & Tracking Platform <span class="mx-1">·</span> Aktivitas administrator tercatat untuk audit</footer>
    </div>
  </div>
</template>
