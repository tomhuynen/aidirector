<template>
  <div
    :class="
      cn(
        'relative flex items-center justify-center overflow-hidden rounded-md border border-border bg-paper-deep',
        props.class,
      )
    "
  >
    <svg
      class="absolute inset-0 size-full text-border"
      preserveAspectRatio="none"
      viewBox="0 0 100 100"
      aria-hidden="true"
    >
      <line
        x1="0"
        y1="0"
        x2="100"
        y2="100"
        stroke="currentColor"
        stroke-width="0.6"
        vector-effect="non-scaling-stroke"
      />
      <line
        x1="100"
        y1="0"
        x2="0"
        y2="100"
        stroke="currentColor"
        stroke-width="0.6"
        vector-effect="non-scaling-stroke"
      />
    </svg>
    <div v-if="$slots.default" class="relative">
      <slot />
    </div>
  </div>
</template>
<script setup lang="ts">
import { cn } from '@shared/lib/utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>
