<template>
  <div class="flex min-h-0 min-w-0 flex-1 flex-col">
    <div class="flex min-h-0 flex-1">
      <section class="flex min-h-0 min-w-0 flex-1 flex-col gap-4 p-6">
        <div
          v-if="error && !generating"
          class="flex items-center justify-between gap-4 rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm"
        >
          <p>{{ error }}</p>
          <Button
            v-if="!choosing.active"
            type="button"
            variant="outline"
            size="sm"
            :disabled="retry.processing"
            @click="retryImages"
          >
            <RefreshCw class="size-4" :class="retry.processing && 'animate-spin'" />
            {{ $t('Generate images again') }}
          </Button>
        </div>

        <FirstKeyframeChooser
          v-if="choosing.active && keyframes[0]"
          :options="keyframes[0].renders"
          :option-count="choosing.optionCount"
          :aspect-ratio="aspectRatio"
          :pending="choosing.pending"
          :choose-url="choosing.chooseUrl"
          :more-url="choosing.moreUrl"
        />
        <div v-else class="flex min-h-0 flex-1 items-center justify-center">
          <video
            v-if="showVideo && video.url"
            :key="video.url"
            :src="video.url"
            controls
            autoplay
            loop
            playsinline
            class="max-h-full max-w-full rounded-xl border border-border bg-black"
            :style="{ aspectRatio: aspectRatio.replace(':', ' / ') }"
          />
          <img
            v-else-if="!showVideo && !adding && selected?.imageUrl"
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
            <p
              v-if="showVideo && video.pending"
              class="flex items-center gap-2 rounded-lg border border-border bg-background/90 px-4 py-3 text-sm text-signal"
            >
              <LoaderCircle class="size-4 animate-spin" />
              {{ $t('Rendering video…') }}
            </p>
            <p
              v-else-if="showVideo"
              class="rounded-lg border border-border bg-background/90 px-4 py-3 text-sm text-muted-foreground"
            >
              {{ $t('No video yet. Render it from the keyframes.') }}
            </p>
            <p
              v-else-if="adding"
              class="rounded-lg border border-border bg-background/90 px-4 py-3 text-sm text-muted-foreground"
            >
              {{ $t('New keyframe :n. Describe it on the right.', { n: String(keyframes.length + 1) }) }}
            </p>
            <div
              v-else-if="selected"
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
      </section>

      <FirstKeyframeInspector v-if="choosing.active && keyframes[0]" :keyframe="keyframes[0]" />
      <NewKeyframeInspector
        v-else-if="adding"
        :position="keyframes.length + 1"
        :store-url="newKeyframe.storeUrl"
        @added="adding = false"
      />
      <VideoInspector
        v-else-if="showVideo"
        :resolution="video.resolution"
        :resolutions="video.resolutions"
        :aspect-ratio="aspectRatio"
      />
      <KeyframeInspector v-else-if="selected" :key="selected.id" :keyframe="selected" :index="selectedIndex" />
    </div>

    <div class="flex shrink-0 border-t border-border">
      <section class="min-w-0 flex-1 space-y-4 p-6">
        <header class="flex items-start justify-between gap-6">
          <div class="space-y-1">
            <h2 class="text-xl font-semibold">
              {{ $t('Keyframes') }}
              <span class="font-normal text-muted-foreground tabular-nums">({{ keyframes.length }})</span>
            </h2>
            <p class="text-sm text-muted-foreground">
              <template v-if="generating">{{
                $t('The images are being generated. This takes a minute or two.')
              }}</template>
              <template v-else-if="choosing.active">{{
                $t('Choose the first keyframe. The others are drawn to match it.')
              }}</template>
              <template v-else>{{
                $t('Review and edit the keyframes. These will be used to generate the final video.')
              }}</template>
            </p>
          </div>
          <div class="flex shrink-0 items-center gap-2">
            <Button
              type="button"
              variant="outline"
              size="icon"
              :aria-label="$t('Previous keyframe')"
              :disabled="keyframes.length === 0 || (!showVideo && selectedIndex === 0)"
              @click="step(-1)"
            >
              <ChevronLeft class="size-4" />
            </Button>
            <Button
              type="button"
              variant="outline"
              size="icon"
              :aria-label="$t('Next keyframe')"
              :disabled="showVideo || selectedIndex >= keyframes.length - 1"
              @click="step(1)"
            >
              <ChevronRight class="size-4" />
            </Button>
          </div>
        </header>

        <ul class="flex items-start gap-4 overflow-x-auto pb-1">
          <li v-for="(keyframe, i) in keyframes" :key="keyframe.id" class="w-44 shrink-0 space-y-2">
            <button
              type="button"
              :class="
                cn(
                  'relative block h-28 w-full overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-muted-foreground/60',
                  !showVideo && !adding && i === selectedIndex && 'border-signal ring-2 ring-signal/40',
                )
              "
              @click="selectKeyframe(i)"
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
                    !showVideo && !adding && i === selectedIndex && 'bg-signal text-primary-foreground',
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
            <button
              type="button"
              :disabled="!canAdd"
              :class="
                cn(
                  'flex h-28 w-full items-center justify-center rounded-lg border border-dashed border-border bg-card/60 text-muted-foreground transition-colors enabled:hover:border-muted-foreground/60 enabled:hover:text-foreground disabled:opacity-50',
                  adding && 'border-solid border-signal text-signal ring-2 ring-signal/40',
                )
              "
              @click="startAdding"
            >
              <Plus class="size-6" />
            </button>
            <p :class="cn('mt-2 px-1 text-sm text-muted-foreground', adding && 'font-semibold text-foreground')">
              {{ $t('Add keyframe') }}
            </p>
          </li>
        </ul>
      </section>

      <section class="flex w-[22rem] shrink-0 flex-col gap-4 border-l border-border p-6">
        <header class="flex items-start justify-between gap-4">
          <div class="space-y-1">
            <h2 class="text-xl font-semibold">{{ $t('Video') }}</h2>
            <p class="text-sm text-muted-foreground">
              <template v-if="video.pending">{{
                $t('Rendering from the :count keyframes…', { count: String(keyframes.length) })
              }}</template>
              <template v-else-if="video.url">{{
                $t('Generated from the :count keyframes.', { count: String(keyframes.length) })
              }}</template>
              <template v-else>{{
                $t('Render a video from the :count keyframes.', { count: String(keyframes.length) })
              }}</template>
            </p>
          </div>
          <Button
            v-if="video.url && !video.pending"
            type="button"
            variant="outline"
            size="sm"
            :disabled="!canRenderVideo"
            @click="renderVideo"
          >
            <RefreshCw class="size-4" :class="videoForm.processing && 'animate-spin'" />
            {{ $t('Render again') }}
          </Button>
        </header>

        <p
          v-if="video.error && !video.pending"
          class="rounded-lg border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm"
        >
          {{ video.error }}
        </p>
        <InputError :message="videoForm.errors.video" />

        <div class="space-y-2">
          <button
            type="button"
            :class="
              cn(
                'relative flex h-28 w-full items-center justify-center overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-muted-foreground/60',
                showVideo && 'border-signal ring-2 ring-signal/40',
              )
            "
            @click="selectVideo"
          >
            <video
              v-if="video.url"
              :key="video.url"
              :src="`${video.url}#t=0.1`"
              preload="metadata"
              muted
              playsinline
              class="absolute inset-0 size-full object-cover"
            />
            <Placeholder v-else class="absolute inset-0 size-full rounded-none border-0 bg-card" />
            <LoaderCircle v-if="video.pending" class="relative size-6 animate-spin text-signal" />
            <span
              v-else-if="video.url"
              class="relative flex size-11 items-center justify-center rounded-full bg-background/60 backdrop-blur-sm"
            >
              <Play class="size-5 fill-current" />
            </span>
          </button>
          <div class="flex items-start gap-2 px-1">
            <span
              :class="
                cn(
                  'flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary',
                  showVideo && 'bg-signal text-primary-foreground',
                )
              "
            >
              <Film class="size-3.5" />
            </span>
            <div class="min-w-0">
              <p class="truncate text-sm font-semibold">{{ $t('Video') }}</p>
              <p class="text-xs text-muted-foreground tabular-nums">
                {{ video.pending ? $t('Rendering…') : `${duration} s · ${video.resolution}` }}
              </p>
            </div>
          </div>
        </div>

        <Button
          v-if="!video.url && !video.pending"
          type="button"
          class="w-full"
          :disabled="!canRenderVideo"
          @click="renderVideo"
        >
          <LoaderCircle v-if="videoForm.processing" class="size-4 animate-spin" />
          <Clapperboard v-else class="size-4" />
          {{ $t('Render video') }}
        </Button>
      </section>
    </div>
  </div>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { ChevronLeft, ChevronRight, Clapperboard, Film, LoaderCircle, Play, Plus, RefreshCw } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

import FirstKeyframeChooser from './FirstKeyframeChooser.vue'
import FirstKeyframeInspector from './FirstKeyframeInspector.vue'
import KeyframeInspector from './KeyframeInspector.vue'
import NewKeyframeInspector from './NewKeyframeInspector.vue'
import Placeholder from './Placeholder.vue'
import VideoInspector from './VideoInspector.vue'

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

export type PanelChoosing = {
  active: boolean
  pending: boolean
  optionCount: number
  chooseUrl: string
  moreUrl: string
}

export type PanelNewKeyframe = {
  storeUrl: string
  max: number
}

export type PanelVideo = {
  url: string | null
  error: string | null
  pending: boolean
  resolution: string
  resolutions: string[]
  generateUrl: string
}

const props = defineProps<{
  keyframes: PanelKeyframe[]
  aspectRatio: string
  duration: number
  generating: boolean
  error?: string | null
  imagesUrl: string
  video: PanelVideo
  choosing: PanelChoosing
  newKeyframe: PanelNewKeyframe
}>()

const selectedIndex = ref(0)
const showVideo = ref(Boolean(props.video.url || props.video.pending))

const adding = ref(false)

const selectKeyframe = (index: number) => {
  selectedIndex.value = index
  showVideo.value = false
  adding.value = false
}

/**
 * Selects the empty slot at the end of the strip; the right column becomes the form for it.
 */
const startAdding = () => {
  adding.value = true
  showVideo.value = false
}

/**
 * The arrows walk through the keyframes; going back from the video lands on the last keyframe.
 */
const step = (delta: number) => {
  if (showVideo.value) {
    selectKeyframe(props.keyframes.length - 1)

    return
  }

  selectKeyframe(Math.min(Math.max(selectedIndex.value + delta, 0), props.keyframes.length - 1))
}

watch(
  () => props.video.pending,
  (pending) => {
    if (pending) showVideo.value = true
  },
)

watch(
  () => props.video.url,
  (url) => {
    if (url) showVideo.value = true
  },
)

watch(
  () => props.keyframes.length,
  (length, previous) => {
    if (previous !== undefined && length > previous && !props.choosing.active) {
      selectKeyframe(length - 1)
    } else if (selectedIndex.value >= length) {
      selectedIndex.value = 0
    }
  },
)

const selected = computed(() => props.keyframes[selectedIndex.value])

watch(
  () => props.choosing.active,
  (active) => {
    if (active) selectKeyframe(0)
  },
  { immediate: true },
)

const isPortrait = computed(() => {
  const [w, h] = props.aspectRatio.split(':').map(Number)

  return h >= w
})

const timeAt = (index: number) => {
  const count = Math.max(props.keyframes.length - 1, 1)

  return `${((props.duration * index) / count).toFixed(1)} s`
}

const selectVideo = () => {
  showVideo.value = true
  adding.value = false
}

const canAdd = computed(
  () =>
    !props.choosing.active &&
    !props.generating &&
    !props.video.pending &&
    props.keyframes.length > 0 &&
    props.keyframes.length < props.newKeyframe.max &&
    props.keyframes.every((keyframe) => keyframe.imageUrl && keyframe.updateUrl && !keyframe.rendering),
)

const retry = useForm({})
const videoForm = useForm<{ video?: string }>({})

const canRenderVideo = computed(
  () =>
    !props.generating &&
    !props.video.pending &&
    !videoForm.processing &&
    props.keyframes.length > 0 &&
    props.keyframes.every((keyframe) => keyframe.imageUrl && !keyframe.rendering),
)

const renderVideo = () => videoForm.post(props.video.generateUrl, { preserveScroll: true })

const retryImages = () => retry.post(props.imagesUrl, { preserveScroll: true })
</script>
