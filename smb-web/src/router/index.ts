import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { public: true, title: 'Masuk' } },
    { path: '/', component: () => import('../layouts/AppShell.vue'), meta: { requiresAuth: true }, children: [
      { path: '', name: 'overview', component: () => import('../views/OverviewView.vue'), meta: { title: 'Ringkasan' } },
      { path: 'devices', name: 'devices', component: () => import('../views/DevicesView.vue'), meta: { title: 'Perangkat' } },
      { path: 'devices/:deviceId', name: 'device-detail', component: () => import('../views/DeviceDetailView.vue'), meta: { title: 'Detail perangkat' } },
      { path: 'map', name: 'map', component: () => import('../views/MapView.vue'), meta: { title: 'Peta perangkat' } },
      { path: 'sites-teams', name: 'sites-teams', component: () => import('../views/SitesTeamsView.vue'), meta: { title: 'Lokasi & tim' } },
      { path: 'network-violations', name: 'network-violations', component: () => import('../views/NetworkViolationsView.vue'), meta: { title: 'Pelanggaran jaringan', permission: 'sites.view' } },
      { path: 'downloads', name: 'downloads', component: () => import('../views/DownloadsView.vue'), meta: { title: 'Pusat unduhan', permission: 'devices.view' } },
      { path: 'sessions', name: 'sessions', component: () => import('../views/SessionsView.vue'), meta: { title: 'Sesi' } },
      { path: 'users', name: 'users', component: () => import('../views/UsersView.vue'), meta: { title: 'Pengguna', permission: 'users.manage' } },
      { path: 'audit-logs', name: 'audit-logs', component: () => import('../views/AuditLogsView.vue'), meta: { title: 'Audit aktivitas', permission: 'audit.view' } },
      { path: 'telegram', name: 'telegram', component: () => import('../views/TelegramAccountsView.vue'), meta: { title: 'Akses Telegram', permission: 'telegram.manage' } },
    ] },
    { path: '/:pathMatch(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  await auth.bootstrap()
  if (to.meta.requiresAuth && !auth.user) return { name: 'login' }
  if (typeof to.meta.permission === 'string' && !auth.can(to.meta.permission)) return { name: 'overview' }
  if (to.name === 'login' && auth.user) return { name: 'overview' }
  return true
})
