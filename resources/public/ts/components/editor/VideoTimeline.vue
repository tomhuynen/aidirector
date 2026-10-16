<template>
  <div class="flex items-center gap-4">
    <Button
      type="button"
      variant="outline"
      size="icon"
      class="shrink-0"
      :aria-label="playing ? $t('Pause') : $t('Play')"
      @click="toggle"
    >
      <Pause v-if="playing" class="size-4 fill-current" />
      <Play v-else class="size-4 fill-current" />
    </Button>

    <div class="min-w-0 flex-1 space-y-2">
      <!-- The film strip of the whole rendered video; the part outside the handles is cut off. -->
      <div
        ref="track"
        class="relative h-20 cursor-pointer touch-none overflow-hidden rounded-lg border border-border bg-card select-none"
        @pointerdown="press"
        @pointermove="drag"
        @pointerup="release"
        @pointercancel="release"
      >
        <div class="absolute inset-0 flex" aria-hidden="true">
          <img
            v-for="(frame, i) in frames"
            :key="i"
            :src="frame"
            alt=""
            draggable="false"
            class="h-full min-w-0 flex-1 object-cover"
          />
        </div>
        <div class="absolute inset-y-0 left-0 bg-background/75" :style="{ width: share(start) }" aria-hidden="true" />
        <div
          class="absolute inset-y-0 right-0 bg-background/75"
          :style="{ width: share(total - end) }"
          aria-hidden="true"
        />
        <div
          class="pointer-events-none absolute inset-y-0 border-y-2 border-signal"
          :style="{ left: share(start), width: share(end - start) }"
          aria-hidden="true"
        />

        <button
          v-for="handle in handles"
          :key="handle.which"
          type="button"
          role="slider"
          :aria-label="handle.label"
          :aria-valuemin="0"
          :aria-valuemax="total"
          :aria-valuenow="handle.value"
          :aria-valuetext="seconds(handle.value)"
          :class="
            cn(
              'absolute inset-y-0 z-10 flex w-3 cursor-ew-resize items-center justify-center bg-signal focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-signal',
              handle.which === 'start' ? 'rounded-l-md' : '-translate-x-full rounded-r-md',
            )
          "
          :style="{ left: share(handle.value) }"
          @pointerdown.stop="grab(handle.which, $event)"
          @keydown="nudge(handle.which, $event)"
        >
          <span class="h-6 w-0.5 rounded-full bg-primary-foreground/80" />
        </button>

        <!-- The needle: where the video above is now. -->
        <div
          class="pointer-events-none absolute inset-y-0 z-20 w-0.5 -translate-x-1/2 bg-white shadow-[0_0_0_1px_rgba(0,0,0,0.5)]"
          :style="{ left: share(now) }"
          aria-hidden="true"
        >
          <span class="absolute top-0 left-1/2 size-2.5 -translate-x-1/2 rounded-full bg-white" />
        </div>
      </div>

      <div class="flex items-center justify-between gap-4 text-xs text-muted-foreground tabular-nums">
        <span>{{ $t('Starts at :time', { time: seconds(start) }) }}</span>
        <span class="font-medium text-foreground">
          {{ $t(':kept of :total', { kept: seconds(end - start), total: seconds(total) }) }}
        </span>
        <span>{{ $t('Ends at :time', { time: seconds(end) }) }}</span>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Pause, Play } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

/** The shortest the kept part may be, in seconds, as the server allows. */
const MIN_SECONDS = 0.5

/** How many frames the film strip shows. */
const FRAME_COUNT = 12

/**
 * Trims a video: the film strip of the rendered video with a handle at each
 * end of the part that is kept. The video above (`player`) follows: it shows
 * the frame under a handle while it is dragged, and plays the kept part in a
 * loop with a needle that runs along.
 */
const props = defineProps<{
  /** The rendered video the part is cut from. */
  url: string
  /** How long the rendered video is, in seconds. */
  length: number
  /** The video element above, which shows what is trimmed. */
  player: HTMLVideoElement | null
}>()

const start = defineModel<number>('start', { required: true })
const end = defineModel<number>('end', { required: true })

const track = ref<HTMLElement | null>(null)
const frames = ref<string[]>([])
const now = ref(start.value)
const playing = ref(false)

/** The length as the video itself reports it once loaded, otherwise as rendered. */
const total = computed(() =>
  props.player && Number.isFinite(props.player.duration) && props.player.duration > 0
    ? props.player.duration
    : props.length,
)

const handles = computed(() => [
  { which: 'start' as const, value: start.value, label: $t('Start of the video') },
  { which: 'end' as const, value: end.value, label: $t('End of the video') },
])

const share = (time: number) => `${(Math.min(Math.max(time, 0), total.value) / total.value) * 100}%`
const seconds = (time: number) => `${time.toFixed(1)} s`

const timeAt = (clientX: number) => {
  const box = track.value?.getBoundingClientRect()

  if (!box || box.width === 0) return 0

  return Math.min(Math.max((clientX - box.left) / box.width, 0), 1) * total.value
}

/** Moves the video above to a moment. */
const seek = (element: HTMLVideoElement, time: number) => {
  element.currentTime = time
}

/** Shows a moment in the video above, without playing it. */
const show = (time: number) => {
  now.value = time

  if (props.player) seek(props.player, time)
}

const setHandle = (which: 'start' | 'end', time: number) => {
  if (which === 'start') {
    start.value = Math.min(Math.max(time, 0), end.value - MIN_SECONDS)
    show(start.value)
  } else {
    end.value = Math.max(Math.min(time, total.value), start.value + MIN_SECONDS)
    show(end.value)
  }
}

const dragging = ref<'start' | 'end' | 'needle' | null>(null)

const grab = (which: 'start' | 'end', event: PointerEvent) => {
  props.player?.pause()
  dragging.value = which
  track.value?.setPointerCapture(event.pointerId)
}

/** A press on the strip puts the needle there, within the kept part. */
const press = (event: PointerEvent) => {
  props.player?.pause()
  dragging.value = 'needle'
  track.value?.setPointerCapture(event.pointerId)
  show(Math.min(Math.max(timeAt(event.clientX), start.value), end.value))
}

const drag = (event: PointerEvent) => {
  if (dragging.value === null) return

  const time = timeAt(event.clientX)

  if (dragging.value === 'needle') {
    show(Math.min(Math.max(time, start.value), end.value))
  } else {
    setHandle(dragging.value, time)
  }
}

const release = () => (dragging.value = null)

const nudge = (which: 'start' | 'end', event: KeyboardEvent) => {
  const step = event.shiftKey ? 1 : 0.1
  const direction = event.key === 'ArrowLeft' ? -1 : event.key === 'ArrowRight' ? 1 : 0

  if (direction === 0) return

  event.preventDefault()
  props.player?.pause()
  setHandle(which, (which === 'start' ? start.value : end.value) + direction * step)
}

const toggle = () => {
  const player = props.player

  if (!player) return

  if (!player.paused) {
    player.pause()

    return
  }

  if (player.currentTime < start.value || player.currentTime >= end.value - 0.05) seek(player, start.value)

  void player.play()
}

/** Follows the video above: the needle runs along, and playing loops within the kept part. */
let animation = 0
const follow = () => {
  const player = props.player

  if (player) {
    playing.value = !player.paused

    if (!player.paused) {
      if (player.currentTime >= end.value || player.currentTime < start.value - 0.05) seek(player, start.value)

      now.value = player.currentTime
    }
  }

  animation = requestAnimationFrame(follow)
}

/** Draws the film strip from the rendered video; without it the strip stays plain. */
const drawFrames = async (url: string) => {
  frames.value = []
  const video = document.createElement('video')
  video.muted = true
  video.preload = 'auto'
  video.src = url

  try {
    await new Promise<void>((resolve, reject) => {
      video.addEventListener('loadeddata', () => resolve(), { once: true })
      video.addEventListener('error', () => reject(new Error('The video could not be loaded.')), { once: true })
    })

    const canvas = document.createElement('canvas')
    canvas.height = 80
    canvas.width = Math.round((80 * video.videoWidth) / Math.max(video.videoHeight, 1))
    const context = canvas.getContext('2d')
    const drawn: string[] = []

    for (let i = 0; i < FRAME_COUNT; i++) {
      await new Promise<void>((resolve) => {
        video.addEventListener('seeked', () => resolve(), { once: true })
        video.currentTime = ((i + 0.5) / FRAME_COUNT) * video.duration
      })
      context?.drawImage(video, 0, 0, canvas.width, canvas.height)
      drawn.push(canvas.toDataURL('image/jpeg', 0.7))
    }

    if (url === props.url) frames.value = drawn
  } catch {
    frames.value = []
  } finally {
    video.removeAttribute('src')
    video.load()
  }
}

watch(
  () => props.url,
  (url) => void drawFrames(url),
)

watch(
  () => props.player,
  (player) => {
    if (player) show(start.value)
  },
)

onMounted(() => {
  void drawFrames(props.url)
  show(start.value)
  animation = requestAnimationFrame(follow)
})

onBeforeUnmount(() => cancelAnimationFrame(animation))
</script>
