<script setup lang="ts">
import { ref } from 'vue'
import { useForm, Field, ErrorMessage } from 'vee-validate'
import { object, string } from 'yup'
import { KeyRound, ShieldCheck, Smartphone } from '@lucide/vue'
import { useRouter } from 'vue-router'
import { api, apiMessage, type ApiEnvelope } from '../lib/api'
import { useAuthStore } from '../stores/auth'
import TotpEnrollmentPanel from '../components/TotpEnrollmentPanel.vue'
import type { UserSummary } from '../types/platform'

interface LoginChallenge {
  challenge_token: string
  expires_at: string
  methods: string[]
  two_factor_enrollment?: { secret: string; otpauth_uri: string }
}

const router = useRouter()
const auth = useAuthStore()
const busy = ref(false)
const message = ref('')
const challenge = ref<LoginChallenge | null>(null)
const factor = ref('')
const useRecovery = ref(false)
const recoveryCodes = ref<string[]>([])
const { handleSubmit } = useForm({
  validationSchema: object({
    username: string().matches(/^[a-zA-Z0-9._-]{3,64}$/, 'Gunakan 3–64 huruf, angka, titik, garis bawah, atau tanda hubung.').required('User ID wajib diisi.'),
    password: string().required('Password wajib diisi.'),
  }),
  initialValues: { username: '', password: '' },
})

const submitCredentials = handleSubmit(async (values) => {
  busy.value = true
  message.value = ''
  try {
    await api.get('/sanctum/csrf-cookie')
    const response = await api.post<ApiEnvelope<LoginChallenge>>('/api/v1/auth/login', {
      ...values,
      client_type: 'web',
    })
    challenge.value = response.data.data
    useRecovery.value = false
    factor.value = ''
  } catch (error) {
    message.value = apiMessage(error)
  } finally {
    busy.value = false
  }
})

async function verifyFactor() {
  if (!challenge.value) return
  if (useRecovery.value ? factor.value.trim().length < 20 : !/^\d{6}$/.test(factor.value)) {
    message.value = useRecovery.value ? 'Masukkan recovery code yang valid.' : 'Kode authenticator harus terdiri dari 6 digit.'
    return
  }
  busy.value = true
  message.value = ''
  try {
    const response = await api.post<ApiEnvelope<{ user: UserSummary; recovery_codes?: string[] }>>('/api/v1/auth/login/verify', {
      challenge_token: challenge.value.challenge_token,
      ...(useRecovery.value ? { recovery_code: factor.value.trim() } : { totp_code: factor.value }),
    })
    auth.setUser(response.data.data.user)
    recoveryCodes.value = response.data.data.recovery_codes ?? []
    if (recoveryCodes.value.length === 0) await router.replace({ name: 'overview' })
  } catch (error) {
    message.value = apiMessage(error)
  } finally {
    busy.value = false
  }
}

async function continueAfterRecovery() {
  recoveryCodes.value = []
  await router.replace({ name: 'overview' })
}

function returnToLogin() {
  challenge.value = null
  factor.value = ''
  message.value = ''
}
</script>

<template>
  <main class="min-h-screen bg-[#f3f6f8] lg:grid lg:grid-cols-[1.08fr_0.92fr]">
    <section class="relative hidden min-h-screen overflow-hidden bg-navy px-12 py-10 text-white lg:flex lg:flex-col lg:justify-between xl:px-20">
      <div class="absolute -right-32 top-24 h-96 w-96 rounded-full border border-white/10" />
      <div class="absolute -right-14 top-44 h-64 w-64 rounded-full border border-white/10" />
      <div class="absolute bottom-16 left-0 h-72 w-72 rounded-full bg-teal/20 blur-3xl" />
      <div class="relative flex items-center gap-3">
        <div class="grid h-11 w-11 place-items-center rounded-2xl bg-white/10 text-teal-300">
          <ShieldCheck :size="24" :stroke-width="1.8" />
        </div>
        <div>
          <p class="text-lg font-semibold tracking-wide">SMB</p>
          <p class="text-xs text-slate-300">Device Management</p>
        </div>
      </div>
      <div class="relative max-w-xl pb-14">
        <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1.5 text-xs font-medium text-teal-200">
          <span class="h-1.5 w-1.5 rounded-full bg-teal-300" /> Platform perangkat terkelola
        </p>
        <h1 class="max-w-xl text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">Visibilitas yang jelas untuk perangkat yang Anda kelola.</h1>
        <p class="mt-5 max-w-lg text-base leading-7 text-slate-300">Pantau status, kesehatan, lokasi terakhir, dan riwayat command melalui akses administrator yang terotorisasi.</p>
        <div class="mt-10 grid grid-cols-2 gap-3 text-sm text-slate-200">
          <div class="flex items-center gap-2 rounded-xl border border-white/10 bg-white/[0.04] px-3 py-3"><ShieldCheck :size="16" class="text-teal-300" /> Akses terlindungi 2FA</div>
          <div class="flex items-center gap-2 rounded-xl border border-white/10 bg-white/[0.04] px-3 py-3"><Smartphone :size="16" class="text-teal-300" /> Status dari perangkat</div>
        </div>
      </div>
      <p class="relative text-xs text-slate-400">Akses hanya untuk perangkat yang Anda miliki atau kelola secara sah.</p>
    </section>

    <section class="flex min-h-screen items-center justify-center px-5 py-10 sm:px-10">
      <div class="w-full max-w-[440px]">
        <div class="mb-8 flex items-center gap-3 lg:hidden">
          <div class="grid h-10 w-10 place-items-center rounded-xl bg-navy text-teal-300"><ShieldCheck :size="21" /></div>
          <div><p class="font-semibold text-ink">SMB</p><p class="text-xs text-muted">Device Management</p></div>
        </div>

        <template v-if="recoveryCodes.length">
          <div class="mb-6 grid h-12 w-12 place-items-center rounded-2xl bg-teal/10 text-teal"><KeyRound :size="23" /></div>
          <p class="text-sm font-semibold uppercase tracking-[0.16em] text-teal">Simpan recovery code</p>
          <h2 class="mt-2 text-3xl font-semibold tracking-tight text-ink">Kode ditampilkan satu kali</h2>
          <p class="mt-3 text-sm leading-6 text-muted">Simpan kode ini di tempat aman. Setiap kode hanya dapat digunakan satu kali untuk pemulihan akun.</p>
          <div class="mt-6 grid grid-cols-2 gap-2 rounded-2xl border border-line bg-white p-4 font-mono text-sm shadow-card">
            <span v-for="code in recoveryCodes" :key="code" class="rounded-lg bg-canvas px-3 py-2 text-ink">{{ code }}</span>
          </div>
          <button class="mt-6 w-full rounded-xl bg-navy px-4 py-3 text-sm font-semibold text-white transition hover:bg-ocean" @click="continueAfterRecovery">Saya sudah menyimpan kode</button>
        </template>

        <template v-else>
          <div class="mb-6 grid h-12 w-12 place-items-center rounded-2xl bg-teal/10 text-teal"><ShieldCheck :size="23" /></div>
          <template v-if="!challenge">
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-teal">Portal administrator</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink">Selamat datang kembali</h1>
            <p class="mt-3 text-sm leading-6 text-muted">Masuk dengan akun SMB Anda untuk melihat perangkat dalam cakupan akses Anda.</p>
            <form class="mt-8 space-y-5" @submit="submitCredentials">
              <div>
                <label class="mb-2 block text-sm font-medium text-ink" for="username">User ID</label>
                <Field id="username" name="username" type="text" autocomplete="username" autocapitalize="none" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm text-ink outline-none transition placeholder:text-slate-600 focus:border-teal focus:ring-4 focus:ring-teal/10" placeholder="mastermarwan" />
                <ErrorMessage name="username" class="mt-1.5 block text-xs text-rose" />
              </div>
              <div>
                <label class="mb-2 block text-sm font-medium text-ink" for="password">Password</label>
                <Field id="password" name="password" type="password" autocomplete="current-password" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-sm text-ink outline-none transition placeholder:text-slate-600 focus:border-teal focus:ring-4 focus:ring-teal/10" placeholder="Masukkan password" />
                <ErrorMessage name="password" class="mt-1.5 block text-xs text-rose" />
              </div>
              <p v-if="message" role="alert" class="rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ message }}</p>
              <button class="flex w-full items-center justify-center gap-2 rounded-xl bg-navy px-4 py-3.5 text-sm font-semibold text-white shadow-sm transition hover:bg-ocean disabled:cursor-not-allowed disabled:opacity-60" :disabled="busy">
                {{ busy ? 'Memeriksa akun…' : 'Lanjutkan dengan 2FA' }}
                <span v-if="!busy">→</span>
              </button>
            </form>
          </template>

          <template v-else>
            <p class="text-sm font-semibold uppercase tracking-[0.16em] text-teal">Verifikasi keamanan</p>
            <h1 class="mt-2 text-3xl font-semibold tracking-tight text-ink">Konfirmasi identitas Anda</h1>
            <p class="mt-3 text-sm leading-6 text-muted">{{ challenge.two_factor_enrollment ? 'Tambahkan secret ini ke aplikasi authenticator, lalu masukkan kode 6 digit.' : 'Masukkan kode authenticator untuk menyelesaikan login.' }}</p>
            <TotpEnrollmentPanel
              v-if="challenge.two_factor_enrollment"
              :otpauth-uri="challenge.two_factor_enrollment.otpauth_uri"
              :secret="challenge.two_factor_enrollment.secret"
            />
            <form class="mt-6 space-y-4" @submit.prevent="verifyFactor">
              <label class="block text-sm font-medium text-ink" for="factor">{{ useRecovery ? 'Recovery code' : 'Kode authenticator' }}</label>
              <input id="factor" v-model="factor" :type="useRecovery ? 'text' : 'text'" :inputmode="useRecovery ? 'text' : 'numeric'" :autocomplete="useRecovery ? 'one-time-code' : 'one-time-code'" :maxlength="useRecovery ? 32 : 6" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-center font-mono text-lg tracking-[0.28em] text-ink outline-none focus:border-teal focus:ring-4 focus:ring-teal/10" :placeholder="useRecovery ? 'Recovery code' : '000000'" />
              <button v-if="!challenge.two_factor_enrollment" type="button" class="text-sm font-medium text-ocean hover:text-teal" @click="useRecovery = !useRecovery; factor = ''">{{ useRecovery ? 'Gunakan kode authenticator' : 'Gunakan recovery code' }}</button>
              <p v-if="message" role="alert" class="rounded-xl border border-rose/20 bg-rose/5 px-4 py-3 text-sm text-rose">{{ message }}</p>
              <button class="w-full rounded-xl bg-navy px-4 py-3.5 text-sm font-semibold text-white transition hover:bg-ocean disabled:opacity-60" :disabled="busy">{{ busy ? 'Memverifikasi…' : 'Verifikasi dan masuk' }}</button>
              <button type="button" class="w-full py-2 text-sm text-muted hover:text-ink" @click="returnToLogin">Kembali ke login</button>
            </form>
          </template>
        </template>
        <p class="mt-8 text-center text-xs leading-5 text-muted">Dengan masuk, Anda menggunakan SMB hanya untuk perangkat yang berizin dan berada dalam cakupan administrator.</p>
      </div>
    </section>
  </main>
</template>
