<template>
  <aside class="flex w-[22rem] shrink-0 flex-col gap-6 overflow-y-auto border-l border-border p-6">
    <div class="space-y-1">
      <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ $t('Video') }}</p>
      <h2 class="text-xl font-semibold">{{ $t('Output') }}</h2>
    </div>

    <div class="space-y-1.5">
      <p class="text-sm text-muted-foreground">{{ $t('Resolution') }}</p>
      <p class="rounded-lg border border-border bg-card px-3.5 py-2.5 text-[15px] tabular-nums">
        {{ resolution }} · {{ current.width }} × {{ current.height }}
      </p>
      <p class="text-sm text-muted-foreground">{{ $t('Set on the project, for all of its shots.') }}</p>
    </div>

    <figure class="space-y-2">
      <svg
        :viewBox="`-2 -2 ${largest.width + 4} ${largest.height + 4}`"
        class="max-h-64 w-full"
        preserveAspectRatio="xMidYMax meet"
        aria-hidden="true"
      >
        <rect
          v-for="option in resolutions"
          :key="option"
          :x="0"
          :y="largest.height - dimensionsOf(option).height"
          :width="dimensionsOf(option).width"
          :height="dimensionsOf(option).height"
          fill="none"
          vector-effect="non-scaling-stroke"
          :stroke-width="option === resolution ? 2 : 1"
          :class="option === resolution ? 'stroke-signal' : 'stroke-border'"
        />
      </svg>
      <figcaption class="flex items-center justify-between text-xs text-muted-foreground tabular-nums">
        <span>{{ aspectRatio }}</span>
        <span class="font-medium text-foreground">{{ current.width }} × {{ current.height }} px</span>
      </figcaption>
    </figure>
  </aside>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { computed } from 'vue'

const props = defineProps<{
  resolution: string
  resolutions: string[]
  aspectRatio: string
}>()

const shortEdges: Record<string, number> = { '480p': 480, '720p': 720, '1080p': 1080, '4K': 2160 }

/**
 * The frame size of a resolution: its short edge, with the long edge from the shot's aspect ratio.
 */
const dimensionsOf = (option: string) => {
  const [w, h] = props.aspectRatio.split(':').map(Number)
  const short = shortEdges[option] ?? 720
  const long = Math.round((short * Math.max(w, h)) / Math.min(w, h))

  return w >= h ? { width: long, height: short } : { width: short, height: long }
}

const current = computed(() => dimensionsOf(props.resolution))

const largest = computed(() => dimensionsOf(props.resolutions[props.resolutions.length - 1] ?? props.resolution))
</script>
