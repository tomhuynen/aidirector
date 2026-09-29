<template>
  <div
    class="flex flex-col items-start gap-4 rounded-lg border border-dashed border-border bg-paper-deep/60 px-8 py-12 md:items-center md:text-center"
  >
    <h2 class="font-display text-2xl font-medium">{{ title }}</h2>
    <p class="max-w-md text-sm leading-relaxed text-muted-foreground">{{ description }}</p>
    <div v-if="$slots.default" class="pt-2">
      <slot />
    </div>
  </div>
</template>
<script setup lang="ts">
defineProps<{
  title: string
  description: string
}>()
</script>
