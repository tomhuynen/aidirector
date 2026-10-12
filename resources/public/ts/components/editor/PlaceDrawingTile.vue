<template>
  <!-- A place being drawn, at the size of the places beside it, with the same progress as a keyframe. -->
  <div class="flex w-full flex-col gap-1 rounded-lg border border-border bg-card p-1.5">
    <span class="relative block w-full overflow-hidden rounded-md" :style="{ aspectRatio }">
      <Placeholder class="size-full rounded-none border-0 bg-card" />
      <RenderProgress :progress-key="progressKey" active :seconds="seconds" />
    </span>
    <span class="text-xs font-medium text-muted-foreground">{{ label }}</span>
  </div>
</template>

<script setup lang="ts">
import Placeholder from './Placeholder.vue'
import RenderProgress from './RenderProgress.vue'

defineProps<{
  /** Names the drawing, so its progress goes on after a reload. */
  progressKey: string
  /** Like "9 / 16". */
  aspectRatio: string
  /** How long drawing one usually takes. */
  seconds: number
  label: string
}>()
</script>
