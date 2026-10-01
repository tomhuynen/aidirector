<template>
  <div class="space-y-5">
    <div class="space-y-1">
      <h2 class="text-xl font-semibold">{{ $t('Outputs') }}</h2>
      <p class="text-sm text-muted-foreground">{{ $t('Pick every format the videos should be delivered in.') }}</p>
    </div>

    <ul class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
      <li
        v-for="ratio in formats.aspectRatios"
        :key="ratio.value"
        :class="
          cn(
            'flex flex-col items-center gap-3 rounded-xl border border-border px-3 pt-4 pb-3 text-center',
            ratioSelected(ratio.value) && 'border-signal/50 bg-signal-soft/20',
          )
        "
      >
        <span class="flex size-14 items-center justify-center">
          <span
            :class="
              cn(
                'block rounded-[4px] border-2',
                ratioSelected(ratio.value) ? 'border-signal' : 'border-muted-foreground/50',
              )
            "
            :style="frameStyle(ratio.value)"
          />
        </span>
        <span class="leading-tight">
          <span class="block text-sm font-semibold tabular-nums">{{ ratio.value }}</span>
          <span class="block text-xs text-muted-foreground">{{ ratio.name }}</span>
          <span v-if="ratio.value === keyframeRatio" class="block text-[11px] text-signal">{{ $t('keyframes') }}</span>
        </span>
        <span class="grid w-full grid-cols-2 gap-1">
          <button
            v-for="resolution in formats.resolutions"
            :key="resolution"
            type="button"
            :aria-pressed="isSelected(ratio.value, resolution)"
            :title="sizeLabel(ratio.value, resolution)"
            :class="
              cn(
                'h-7 rounded-md border text-[11px] font-medium tabular-nums transition-colors',
                isSelected(ratio.value, resolution)
                  ? 'border-signal bg-signal text-primary-foreground'
                  : 'border-border text-muted-foreground hover:border-muted-foreground/60 hover:text-foreground',
              )
            "
            @click="toggle(ratio.value, resolution)"
          >
            {{ resolution }}
          </button>
        </span>
      </li>
    </ul>

    <div class="space-y-2 border-t border-border pt-4">
      <p class="text-sm text-muted-foreground">
        {{ selected.length === 1 ? $t('1 output') : $t(':count outputs', { count: String(selected.length) }) }}
      </p>
      <ul class="flex flex-wrap gap-1.5">
        <li
          v-for="output in selected"
          :key="key(output.aspectRatio, output.resolution)"
          class="rounded-full border border-border bg-background/60 px-2.5 py-0.5 text-xs tabular-nums"
        >
          {{ output.aspectRatio }} · {{ output.resolution }} · {{ sizeLabel(output.aspectRatio, output.resolution) }}
        </li>
      </ul>
      <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </div>
  </div>
</template>
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { ref, watch } from 'vue'

export type VideoOutput = { aspectRatio: string; resolution: string }

export type VideoFormatCatalogue = {
  aspectRatios: { value: string; name: string }[]
  resolutions: string[]
  sizes: Record<string, { width: number; height: number }>
}

const props = defineProps<{
  formats: VideoFormatCatalogue
  outputs: VideoOutput[]
  keyframeRatio: string
  saveUrl: string
}>()

const selected = ref<VideoOutput[]>([...props.outputs])
const error = ref<string | null>(null)

watch(
  () => props.outputs,
  (outputs) => {
    selected.value = [...outputs]
  },
)

const key = (ratio: string, resolution: string) => `${ratio} ${resolution}`

const isSelected = (ratio: string, resolution: string) =>
  selected.value.some((output) => output.aspectRatio === ratio && output.resolution === resolution)

const ratioSelected = (ratio: string) => selected.value.some((output) => output.aspectRatio === ratio)

const sizeLabel = (ratio: string, resolution: string) => {
  const size = props.formats.sizes[key(ratio, resolution)]

  return size ? `${size.width} × ${size.height}` : ''
}

/**
 * A small outline in the ratio's shape, fitted into a 48 px square.
 */
const frameStyle = (ratio: string) => {
  const [w, h] = ratio.split(':').map(Number)
  const scale = 48 / Math.max(w, h)

  return { width: `${Math.round(w * scale)}px`, height: `${Math.round(h * scale)}px` }
}

/**
 * Toggles one output and saves straight away; the last output cannot be switched off.
 */
const toggle = (ratio: string, resolution: string) => {
  const previous = [...selected.value]
  const next = isSelected(ratio, resolution)
    ? previous.filter((output) => !(output.aspectRatio === ratio && output.resolution === resolution))
    : [...previous, { aspectRatio: ratio, resolution }]

  if (next.length === 0) {
    error.value = $t('Keep at least one output.')

    return
  }

  error.value = null
  selected.value = next

  router.post(
    props.saveUrl,
    { outputs: next },
    {
      preserveScroll: true,
      preserveState: true,
      only: ['project'],
      onError: () => {
        selected.value = previous
        error.value = $t('The outputs could not be saved.')
      },
    },
  )
}
</script>
