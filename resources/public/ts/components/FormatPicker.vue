<template>
  <div class="space-y-5">
    <div class="space-y-1">
      <h2 class="text-xl font-semibold">{{ $t('Format') }}</h2>
      <p class="text-sm text-muted-foreground">
        {{ $t('The shape and resolution every keyframe and video is made in.') }}
      </p>
    </div>

    <ul class="grid gap-3 sm:grid-cols-3">
      <li
        v-for="ratio in formats.aspectRatios"
        :key="ratio.value"
        :class="
          cn(
            'flex items-center gap-4 rounded-xl border border-border p-4',
            ratio.value === current.aspectRatio && 'border-signal/50 bg-signal-soft/20',
          )
        "
      >
        <span class="flex size-14 shrink-0 items-center justify-center">
          <span
            :class="
              cn(
                'block rounded-[4px] border-2',
                ratio.value === current.aspectRatio ? 'border-signal' : 'border-muted-foreground/50',
              )
            "
            :style="frameStyle(ratio.value)"
          />
        </span>
        <span class="min-w-0 flex-1 space-y-2">
          <span class="block leading-tight">
            <span class="text-sm font-semibold tabular-nums">{{ ratio.value }}</span>
            <span class="ml-1.5 text-xs text-muted-foreground">{{ ratio.name }}</span>
          </span>
          <span class="grid grid-cols-4 gap-1">
            <button
              v-for="option in formats.resolutions"
              :key="option"
              type="button"
              :aria-pressed="isCurrent(ratio.value, option)"
              :title="sizeLabel(ratio.value, option)"
              :disabled="saving"
              :class="
                cn(
                  'h-7 rounded-md border text-[11px] font-medium tabular-nums transition-colors',
                  isCurrent(ratio.value, option)
                    ? 'border-signal bg-signal text-primary-foreground'
                    : 'border-border text-muted-foreground hover:border-muted-foreground/60 hover:text-foreground',
                )
              "
              @click="choose(ratio.value, option)"
            >
              {{ option }}
            </button>
          </span>
        </span>
      </li>
    </ul>

    <p class="text-sm text-muted-foreground tabular-nums">
      {{ current.aspectRatio }} · {{ current.resolution }} · {{ sizeLabel(current.aspectRatio, current.resolution) }}
      <template v-if="hasShots">
        · {{ $t('Shots already drawn keep their format until they are drawn again.') }}
      </template>
    </p>
    <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
  </div>
</template>
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { ref, watch } from 'vue'

export type VideoFormatCatalogue = {
  aspectRatios: { value: string; name: string }[]
  resolutions: string[]
  sizes: Record<string, { width: number; height: number }>
}

const props = defineProps<{
  formats: VideoFormatCatalogue
  aspectRatio: string
  resolution: string
  hasShots: boolean
  saveUrl: string
}>()

const current = ref({ aspectRatio: props.aspectRatio, resolution: props.resolution })
const saving = ref(false)
const error = ref<string | null>(null)

watch(
  () => [props.aspectRatio, props.resolution],
  ([aspectRatio, resolution]) => {
    current.value = { aspectRatio, resolution }
  },
)

const isCurrent = (ratio: string, resolution: string) =>
  current.value.aspectRatio === ratio && current.value.resolution === resolution

const sizeLabel = (ratio: string, resolution: string) => {
  const size = props.formats.sizes[`${ratio} ${resolution}`]

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
 * Makes a ratio and resolution the project's format and saves straight away.
 */
const choose = (ratio: string, resolution: string) => {
  if (isCurrent(ratio, resolution)) return

  const previous = { ...current.value }

  current.value = { aspectRatio: ratio, resolution }
  error.value = null
  saving.value = true

  router.post(
    props.saveUrl,
    { aspectRatio: ratio, resolution },
    {
      preserveScroll: true,
      preserveState: true,
      only: ['project'],
      onError: () => {
        current.value = previous
        error.value = $t('The format could not be saved.')
      },
      onFinish: () => {
        saving.value = false
      },
    },
  )
}
</script>
