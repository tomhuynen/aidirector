<template>
  <div class="flex min-h-0 min-w-0 flex-1">
    <section class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto p-6">
      <header class="space-y-1">
        <h2 class="text-2xl font-semibold">{{ $t('Keyframes') }}</h2>
        <p class="text-muted-foreground">
          <template v-if="generating">{{ $t('The images are being generated. This takes a minute or two.') }}</template>
          <template v-else>{{
            $t('Review the keyframes for this shot. The video follows in the next step.')
          }}</template>
        </p>
      </header>

      <div
        v-if="error && !generating"
        class="mt-4 flex items-center justify-between gap-4 rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm"
      >
        <p>{{ error }}</p>
        <Button type="button" variant="outline" size="sm" :disabled="retry.processing" @click="retryImages">
          <RefreshCw class="size-4" :class="retry.processing && 'animate-spin'" />
          {{ $t('Generate images again') }}
        </Button>
      </div>

      <div class="mt-6 flex min-h-0 flex-1 items-center justify-center">
        <img
          v-if="selected?.imageUrl"
          :src="selected.imageUrl"
          :alt="selected.title"
          :class="
            cn(
              'max-h-full max-w-full rounded-xl border border-border bg-card object-contain',
              selected.rendering && 'opacity-50',
            )
          "
          :style="{ aspectRatio: aspectRatio.replace(':', ' / ') }"
        />
        <Placeholder
          v-else
          class="max-h-full max-w-full rounded-xl bg-card"
          :style="{
            aspectRatio: aspectRatio.replace(':', ' / '),
            height: isPortrait ? '100%' : undefined,
            width: isPortrait ? undefined : '100%',
          }"
        >
          <div
            v-if="selected"
            class="max-w-md space-y-2 rounded-lg border border-border bg-background/90 px-5 py-4 text-center"
          >
            <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
              {{ $t('Keyframe :n of :total', { n: String(selectedIndex + 1), total: String(keyframes.length) }) }}
            </p>
            <h3 class="font-semibold">{{ selected.title }}</h3>
            <p class="text-sm leading-relaxed text-muted-foreground">{{ selected.description }}</p>
            <p v-if="selected.rendering" class="flex items-center justify-center gap-2 pt-1 text-xs text-signal">
              <LoaderCircle class="size-3.5 animate-spin" />
              {{ $t('Generating image…') }}
            </p>
          </div>
        </Placeholder>
      </div>

      <ul class="mt-5 flex shrink-0 items-start gap-4 overflow-x-auto pb-1">
        <li v-for="(keyframe, i) in keyframes" :key="keyframe.id" class="w-44 shrink-0 space-y-2">
          <button
            type="button"
            :class="
              cn(
                'relative block h-28 w-full overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-muted-foreground/60',
                i === selectedIndex && 'border-signal ring-2 ring-signal/40',
              )
            "
            @click="selectedIndex = i"
          >
            <img
              v-if="keyframe.thumbnailUrl"
              :src="keyframe.thumbnailUrl"
              :alt="keyframe.title"
              :class="cn('size-full object-cover', keyframe.rendering && 'opacity-50')"
            />
            <Placeholder v-else class="size-full rounded-none border-0 bg-card" />
            <span
              v-if="keyframe.rendering"
              class="absolute inset-0 flex items-center justify-center bg-background/40 text-signal"
            >
              <LoaderCircle class="size-5 animate-spin" />
            </span>
          </button>
          <div class="flex items-start gap-2 px-1">
            <span
              :class="
                cn(
                  'flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary text-xs font-semibold tabular-nums',
                  i === selectedIndex && 'bg-signal text-primary-foreground',
                )
              "
            >
              {{ i + 1 }}
            </span>
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold">{{ keyframe.title }}</p>
              <p class="text-xs text-muted-foreground tabular-nums">{{ timeAt(i) }}</p>
            </div>
          </div>
        </li>
        <li class="w-44 shrink-0">
          <div
            class="flex h-28 w-full items-center justify-center rounded-lg border border-dashed border-border bg-card/60 text-muted-foreground"
          >
            <Plus class="size-6" />
          </div>
          <p class="mt-2 px-1 text-sm text-muted-foreground">{{ $t('Add keyframe') }}</p>
        </li>
      </ul>
    </section>

    <KeyframeInspector v-if="selected" :key="selected.id" :keyframe="selected" :index="selectedIndex" />
  </div>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { LoaderCircle, Plus, RefreshCw } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

import KeyframeInspector from './KeyframeInspector.vue'
import Placeholder from './Placeholder.vue'

export type PanelKeyframe = {
  id: string
  title: string
  description: string
  imageUrl: string | null
  thumbnailUrl: string | null
  rendering: boolean
  renderError: string | null
  renders: { id: number; chosen: boolean; imageUrl: string; thumbnailUrl: string }[]
  updateUrl: string | null
  tweakUrl: string | null
  chooseRenderUrl: string | null
}

const props = defineProps<{
  keyframes: PanelKeyframe[]
  aspectRatio: string
  duration: number
  generating: boolean
  error?: string | null
  imagesUrl: string
}>()

const selectedIndex = ref(0)

watch(
  () => props.keyframes.length,
  (length) => {
    if (selectedIndex.value >= length) selectedIndex.value = 0
  },
)

const selected = computed(() => props.keyframes[selectedIndex.value])

const isPortrait = computed(() => {
  const [w, h] = props.aspectRatio.split(':').map(Number)

  return h >= w
})

const timeAt = (index: number) => {
  const count = Math.max(props.keyframes.length - 1, 1)

  return `${((props.duration * index) / count).toFixed(1)} s`
}

const retry = useForm({})

const retryImages = () => retry.post(props.imagesUrl, { preserveScroll: true })
</script>
