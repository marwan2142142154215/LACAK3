<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Plus, RefreshCw, ShieldCheck, Users } from '@lucide/vue'
import PageHeader from '../components/PageHeader.vue'
import { api, apiMessage, type ApiEnvelope, type Paginated } from '../lib/api'
import { useAuthStore } from '../stores/auth'

interface UserRecord {
  id: number
  name: string
  username: string
  email: string | null
  is_active: boolean
  two_factor_enabled: boolean
  roles: string[]
  sites: Array<{ id: number; name: string; code: string }>
}

interface SiteOption { id: number; name: string; code: string }

const auth = useAuthStore()
const users = ref<UserRecord[]>([])
const sites = ref<SiteOption[]>([])
const page = ref(1)
const pagination = ref({ total: 0, current_page: 1, last_page: 1, per_page: 25 })
const query = ref('')
const loading = ref(false)
const saving = ref(false)
const formOpen = ref(false)
const error = ref('')
const notice = ref('')
const isSuperAdmin = computed(() => auth.user?.roles.includes('SUPER_ADMIN') ?? false)
const roleOptions = computed(() => isSuperAdmin.value
  ? ['SUPER_ADMIN', 'ADMIN', 'OPERATOR', 'VIEWER']
  : ['OPERATOR', 'VIEWER'])
const form = ref({ name: '', username: '', email: '', password: '', password_confirmation: '', role: 'OPERATOR', site_id: '' })

async function loadUsers(nextPage = page.value) {
  loading.value = true
  error.value = ''
  try {
    const response = await api.get<ApiEnvelope<Paginated<UserRecord>>>('/api/v1/users', {
      params: { page: nextPage, per_page: 25, ...(query.value.trim() ? { q: query.value.trim() } : {}) },
    })
    users.value = response.data.data.items
    pagination.value = response.data.data.pagination
    page.value = pagination.value.current_page
  } catch (requestError) {
    error.value = apiMessage(requestError)
  } finally {
    loading.value = false
  }
}

async function loadSites() {
  const response = await api.get<ApiEnvelope<Paginated<SiteOption>>>('/api/v1/sites', { params: { page: 1, per_page: 100 } })
  sites.value = response.data.data.items
}

async function createUser() {
  if (!auth.can('users.manage')) return
  if (form.value.role === 'SUPER_ADMIN' && !isSuperAdmin.value) {
    error.value = 'Hanya SUPER_ADMIN yang dapat menambah SUPER_ADMIN.'
    return
  }
  saving.value = true
  error.value = ''
  notice.value = ''
  try {
    await api.post('/api/v1/users', {
      name: form.value.name.trim(),
      username: form.value.username.trim().toLowerCase(),
      ...(form.value.email.trim() ? { email: form.value.email.trim() } : {}),
      password: form.value.password,
      password_confirmation: form.value.password_confirmation,
      roles: [form.value.role],
      site_ids: form.value.role === 'SUPER_ADMIN' ? [] : [Number(form.value.site_id)],
    })
    form.value = { name: '', username: '', email: '', password: '', password_confirmation: '', role: isSuperAdmin.value ? 'SUPER_ADMIN' : 'OPERATOR', site_id: '' }
    formOpen.value = false
    notice.value = 'Pengguna dibuat. Saat login pertama, pengguna wajib mengaktifkan 2FA sebelum masuk.'
    await loadUsers(1)
  } catch (requestError) {
    error.value = apiMessage(requestError)
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  await Promise.all([loadUsers(1), loadSites().catch((requestError) => { error.value = apiMessage(requestError) })])
  if (isSuperAdmin.value) form.value.role = 'SUPER_ADMIN'
})
</script>

<template>
  <PageHeader eyebrow="Administrasi" title="Pengguna dan administrator" description="Kelola akun, role, cakupan lokasi, dan status 2FA. Pemberian role tetap divalidasi Laravel." />
  <div v-if="error" role="alert" class="mb-4 rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ error }}</div>
  <div v-if="notice" role="status" class="mb-4 rounded-xl border border-teal/20 bg-teal/5 px-4 py-3 text-sm text-ink">{{ notice }}</div>

  <section class="rounded-2xl border border-line bg-white p-5 shadow-card sm:p-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div class="flex items-center gap-3"><span class="grid h-10 w-10 place-items-center rounded-xl bg-blue-50 text-ocean"><Users :size="19" /></span><div><h2 class="font-semibold text-ink">Akun terdaftar</h2><p class="text-xs text-muted">{{ pagination.total }} pengguna</p></div></div>
      <button v-if="auth.can('users.manage')" class="inline-flex items-center gap-2 rounded-lg bg-navy px-3 py-2.5 text-xs font-semibold text-white hover:bg-ocean" @click="formOpen = !formOpen"><Plus :size="15" />{{ formOpen ? 'Tutup form' : 'Tambah pengguna' }}</button>
    </div>

    <form v-if="formOpen" class="mt-5 grid gap-3 rounded-xl bg-canvas p-4 md:grid-cols-2" @submit.prevent="createUser">
      <label class="text-xs font-semibold text-ink">Nama tampilan<input v-model.trim="form.name" required maxlength="160" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm font-normal" placeholder="Nama administrator" /></label>
      <label class="text-xs font-semibold text-ink">User ID<input v-model.trim="form.username" required minlength="3" maxlength="64" pattern="[A-Za-z0-9._-]+" autocapitalize="none" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm font-normal" placeholder="mastermarwan" /></label>
      <label class="text-xs font-semibold text-ink">Email (opsional)<input v-model.trim="form.email" type="email" maxlength="254" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm font-normal" placeholder="alamat untuk kontak" /></label>
      <label class="text-xs font-semibold text-ink">Role<select v-model="form.role" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm font-normal"><option v-for="role in roleOptions" :key="role" :value="role">{{ role }}</option></select></label>
      <label v-if="form.role !== 'SUPER_ADMIN'" class="text-xs font-semibold text-ink md:col-span-2">Lokasi wajib untuk role non-SUPER_ADMIN<select v-model="form.site_id" required class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm font-normal"><option value="" disabled>Pilih lokasi</option><option v-for="site in sites" :key="site.id" :value="String(site.id)">{{ site.name }} · {{ site.code }}</option></select></label>
      <label class="text-xs font-semibold text-ink">Password awal<input v-model="form.password" required type="password" minlength="14" autocomplete="new-password" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm font-normal" placeholder="Min. 14 karakter, huruf besar/kecil, angka, simbol" /></label>
      <label class="text-xs font-semibold text-ink">Ulangi password<input v-model="form.password_confirmation" required type="password" minlength="14" autocomplete="new-password" class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm font-normal" placeholder="Ulangi password awal" /></label>
      <p class="flex items-start gap-2 text-xs leading-5 text-muted md:col-span-2"><ShieldCheck :size="15" class="mt-0.5 shrink-0 text-teal" />Akun baru belum dapat masuk sampai menyelesaikan aktivasi TOTP pada login pertama. Password memenuhi kebijakan kuat Laravel.</p>
      <button class="rounded-lg bg-navy px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-60 md:col-span-2" :disabled="saving">{{ saving ? 'Menyimpan…' : `Buat ${form.role}` }}</button>
    </form>

    <form class="mt-5 flex gap-2" @submit.prevent="loadUsers(1)"><input v-model.trim="query" maxlength="160" class="min-w-0 flex-1 rounded-lg border border-line bg-white px-3 py-2.5 text-sm" placeholder="Cari nama atau user ID" /><button class="rounded-lg border border-line px-3 py-2 text-sm font-semibold text-ink">Cari</button><button type="button" class="rounded-lg border border-line p-2 text-muted" aria-label="Muat ulang pengguna" :disabled="loading" @click="loadUsers()"><RefreshCw :size="16" :class="loading ? 'animate-spin' : ''" /></button></form>
    <div v-if="loading" class="py-10 text-center text-sm text-muted">Memuat daftar pengguna…</div>
    <div v-else-if="users.length === 0" class="py-10 text-center text-sm text-muted">Belum ada pengguna dalam cakupan akun.</div>
    <div v-else class="mt-4 overflow-x-auto">
      <table class="w-full min-w-[680px] text-left">
        <thead class="border-b border-line text-[11px] uppercase tracking-wide text-muted"><tr><th class="px-3 py-3 font-semibold">Pengguna</th><th class="px-3 py-3 font-semibold">Role</th><th class="px-3 py-3 font-semibold">Lokasi</th><th class="px-3 py-3 font-semibold">Status</th></tr></thead>
        <tbody class="divide-y divide-line"><tr v-for="user in users" :key="user.id"><td class="px-3 py-3"><p class="text-sm font-semibold text-ink">{{ user.name }}</p><p class="mt-1 text-xs text-muted">{{ user.username }}<span v-if="user.email"> · {{ user.email }}</span></p></td><td class="px-3 py-3 text-xs font-medium text-ink">{{ user.roles.join(', ') }}</td><td class="px-3 py-3 text-xs text-muted">{{ user.roles.includes('SUPER_ADMIN') ? 'Semua lokasi' : user.sites.map((site) => site.name).join(', ') }}</td><td class="px-3 py-3"><span class="rounded-full px-2.5 py-1 text-[11px] font-semibold" :class="user.is_active ? 'bg-teal/10 text-teal' : 'bg-rose-50 text-rose'">{{ user.is_active ? 'Aktif' : 'Nonaktif' }}</span><p class="mt-1 text-[10px] text-muted">2FA {{ user.two_factor_enabled ? 'aktif' : 'belum aktif' }}</p></td></tr></tbody>
      </table>
      <div class="mt-4 flex items-center justify-between border-t border-line pt-4 text-xs text-muted"><span>Halaman {{ pagination.current_page }} dari {{ pagination.last_page }}</span><div class="flex gap-2"><button class="rounded-lg border border-line px-3 py-2 disabled:opacity-40" :disabled="page <= 1 || loading" @click="loadUsers(page - 1)">Sebelumnya</button><button class="rounded-lg border border-line px-3 py-2 disabled:opacity-40" :disabled="page >= pagination.last_page || loading" @click="loadUsers(page + 1)">Berikutnya</button></div></div>
    </div>
  </section>
</template>
