<template>
  <Head :title="title" />

  <div class="flex flex-col gap-10">
    <header class="flex flex-col gap-6 md:flex-row md:items-end md:justify-between">
      <div class="max-w-2xl space-y-3">
        <p v-if="eyebrow" class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase">
          {{ eyebrow }}
        </p>
        <h1 class="font-display text-4xl leading-[1.05] font-medium text-balance md:text-5xl">{{ title }}</h1>
        <p v-if="description" class="text-base leading-relaxed text-muted-foreground">{{ description }}</p>
      </div>
      <div v-if="$slots.actions" class="flex shrink-0 items-center gap-3">
        <slot name="actions" />
      </div>
    </header>

    <slot />
  </div>
</template>
<script setup lang="ts">
import { Head } from '@inertiajs/vue3'

defineProps<{
  title: string
  eyebrow?: string
  description?: string
}>()
</script>
