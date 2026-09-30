<template>
  <section class="flex min-h-0 flex-1 flex-col items-center justify-center gap-4 p-6">
    <span class="flex size-12 items-center justify-center rounded-full bg-signal-soft">
      <LoaderCircle class="size-6 animate-spin text-signal" />
    </span>
    <div class="space-y-1 text-center">
      <h2 class="text-lg font-semibold">{{ title }}</h2>
      <p class="text-sm text-muted-foreground">{{ description }}</p>
    </div>
  </section>
</template>
<script setup lang="ts">
import { LoaderCircle } from 'lucide-vue-next'

defineProps<{
  title: string
  description: string
}>()
</script>
