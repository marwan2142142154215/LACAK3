<script setup lang="ts">
defineProps<{ eyebrow?: string; title: string; description: string }>()
</script>

<template>
  <div class="page-heading mb-6 flex flex-col gap-4 overflow-hidden rounded-[26px] border border-white bg-gradient-to-r from-white via-white to-[#eaf4f8] px-5 py-5 shadow-[0_10px_36px_rgba(16,37,65,0.06)] sm:flex-row sm:items-center sm:justify-between sm:px-7 sm:py-6">
    <div class="relative z-10">
      <p v-if="eyebrow" class="inline-flex items-center gap-2 rounded-full border border-teal/15 bg-teal/5 px-3 py-1 text-xs font-bold uppercase tracking-[0.14em] text-teal"><span class="h-1.5 w-1.5 rounded-full bg-teal" />{{ eyebrow }}</p>
      <h1 class="mt-3 text-[27px] font-bold tracking-tight text-ink sm:text-[32px]">{{ title }}</h1>
      <p class="mt-2 max-w-3xl text-sm leading-6 text-muted sm:text-[15px]">{{ description }}</p>
    </div>
    <div class="relative z-10 flex shrink-0 items-center gap-2"><slot /></div>
  </div>
</template>
