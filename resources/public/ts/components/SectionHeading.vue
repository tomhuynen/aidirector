<template>
  <div class="space-y-1 border-b border-border pb-3">
    <h2 class="font-display text-xl font-medium">{{ title }}</h2>
    <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
  </div>
</template>
<script setup lang="ts">
defineProps<{
  title: string
  description?: string
}>()
</script>
