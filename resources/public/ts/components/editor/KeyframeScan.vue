<template>
  <!-- While something is done to a drawn keyframe, such as reviewing it: a line sweeps over the image, with the label in the centre. -->
  <span class="pointer-events-none absolute inset-0 overflow-hidden bg-black/20" role="status">
    <span class="scan absolute inset-x-0 h-1/3 bg-gradient-to-b from-transparent to-signal/30">
      <span class="absolute inset-x-0 bottom-0 h-0.5 bg-signal shadow-[0_0_10px_var(--color-signal)]" />
    </span>
    <span class="absolute inset-0 flex items-center justify-center">
      <span
        :class="
          cn(
            'rounded-full bg-background/80 font-semibold tracking-wide text-signal uppercase backdrop-blur-sm',
            large ? 'px-4 py-1.5 text-sm' : 'px-2.5 py-0.5 text-[11px]',
          )
        "
      >
        {{ label }}
      </span>
    </span>
  </span>
</template>

<script setup lang="ts">
import { cn } from '@shared/lib/utils'

defineProps<{
  /** What is done to the image, such as reviewing it. */
  label: string
  /** For the large image instead of a thumbnail. */
  large?: boolean
}>()
</script>

<style scoped>
.scan {
  animation: scan 2.4s ease-in-out infinite;
}

@keyframes scan {
  from {
    top: -33%;
  }
  to {
    top: 100%;
  }
}

@media (prefers-reduced-motion: reduce) {
  .scan {
    animation: none;
    display: none;
  }
}
</style>
