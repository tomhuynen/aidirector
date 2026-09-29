<template>
  <div class="space-y-1">
    <dt class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ label }}</dt>
    <dd :class="value ? 'leading-relaxed' : 'text-muted-foreground italic'">{{ value || '—' }}</dd>
  </div>
</template>
<script setup lang="ts">
defineProps<{
  label: string
  value?: string | null
}>()
</script>
