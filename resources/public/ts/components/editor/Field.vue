<template>
  <div class="space-y-1.5">
    <p class="text-sm text-muted-foreground">{{ label }}</p>
    <p
      :class="
        cn(
          'rounded-lg border border-border bg-card px-3.5 py-2.5 text-[15px] leading-relaxed whitespace-pre-line',
          !value && 'text-muted-foreground italic',
        )
      "
    >
      {{ value || '—' }}
    </p>
  </div>
</template>
<script setup lang="ts">
import { cn } from '@shared/lib/utils'

defineProps<{
  label: string
  value?: string | null
}>()
</script>
