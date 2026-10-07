<script setup lang="ts">
import { ref, watch } from 'vue'
import { createTotpQrDataUrl } from '../lib/totpQr'

const props = defineProps<{ otpauthUri: string; secret: string }>()
const qrDataUrl = ref('')
const qrError = ref('')
let generation = 0

watch(() => props.otpauthUri, async (uri) => {
  const currentGeneration = ++generation
  qrDataUrl.value = ''
  qrError.value = ''

  try {
    const result = await createTotpQrDataUrl(uri)
    if (currentGeneration === generation) qrDataUrl.value = result
  } catch {
    if (currentGeneration === generation) {
      qrError.value = 'QR tidak dapat dibuat di browser ini. Masukkan kunci setup manual di aplikasi authenticator.'
    }
  }
}, { immediate: true })
</script>

<template>
  <section class="mt-5 rounded-xl border border-teal/20 bg-teal/5 p-4" aria-label="Setup autentikasi dua faktor">
    <p class="text-xs font-semibold uppercase tracking-wider text-teal">Pindai QR dengan aplikasi authenticator</p>
    <div class="mt-3 flex min-h-56 items-center justify-center rounded-xl bg-white p-3">
      <img v-if="qrDataUrl" :src="qrDataUrl" alt="QR code untuk menyiapkan autentikasi dua faktor" width="224" height="224" class="h-56 w-56" />
      <p v-else class="max-w-xs text-center text-xs leading-5 text-muted">{{ qrError || 'Membuat QR setup…' }}</p>
    </div>
    <p class="mt-3 text-xs leading-5 text-muted">Jika tidak dapat memindai QR, masukkan kunci setup berikut secara manual:</p>
    <code class="mt-2 block select-all break-all rounded-lg bg-white px-3 py-2 font-mono text-sm text-ink">{{ secret }}</code>
  </section>
</template>
