<template>
  <!-- An image revealed from left to right while it is made: grey and dim where it is not done yet. -->
  <span
    v-if="visible"
    :class="cn('absolute inset-0 overflow-hidden transition-opacity duration-300', fading && 'opacity-0')"
    role="progressbar"
    :aria-valuenow="percent"
    aria-valuemin="0"
    aria-valuemax="100"
  >
    <template v-if="imageUrl">
      <img
        :src="imageUrl"
        alt=""
        draggable="false"
        class="absolute inset-0 size-full object-cover opacity-35 grayscale"
      />
      <img
        :src="imageUrl"
        alt=""
        draggable="false"
        :class="cn('absolute inset-0 size-full object-cover transition-[clip-path]', motion)"
        :style="{ clipPath: `inset(0 ${100 - percent}% 0 0)` }"
      />
    </template>
    <span
      v-else
      :class="cn('absolute inset-y-0 left-0 bg-signal/15 transition-[width]', motion)"
      :style="{ width: `${percent}%` }"
    />
    <span
      :class="cn('absolute inset-y-0 w-0.5 bg-signal shadow-[0_0_8px_var(--color-signal)] transition-[left]', motion)"
      :style="{ left: `calc(${percent}% - 1px)` }"
    />
    <span
      class="absolute right-1.5 bottom-1.5 rounded-full bg-background/70 px-1.5 py-0.5 text-[11px] font-semibold text-signal tabular-nums backdrop-blur-sm"
    >
      {{ percent }}%
    </span>
  </span>
</template>

<script setup lang="ts">
import { useRenderProgress } from '@public/ts/composables/useRenderProgress'
import { cn } from '@shared/lib/utils'
import { computed, onBeforeUnmount, ref, toRef, watch } from 'vue'

/** How long the last stretch to 100% takes once the render is ready, and the fade after it. */
const FINISH_MS = 350
const FADE_MS = 300

const props = defineProps<{
  /** Names the render, such as `keyframe:<id>`, so its start is remembered. */
  progressKey: string
  active: boolean
  /** How long this kind of render usually takes. */
  seconds: number
  /** When the server says the render started, as an ISO date. */
  startedAt?: string | null
  /** The image revealed; without one a plain bar fills up. */
  imageUrl?: string | null
}>()

const progress = useRenderProgress(
  toRef(props, 'progressKey'),
  toRef(props, 'active'),
  toRef(props, 'seconds'),
  computed(() => (props.startedAt ? Date.parse(props.startedAt) : null)),
)

const percent = computed(() => Math.round(progress.value * 100))

/** Still shown for a moment after the render is ready: it runs to 100% quickly, then fades out. */
const visible = ref(props.active)
const finishing = ref(false)
const fading = ref(false)
const motion = computed(() => (finishing.value ? 'duration-300 ease-out' : 'duration-500 ease-linear'))
let timers: ReturnType<typeof setTimeout>[] = []

const clearTimers = () => {
  timers.forEach(clearTimeout)
  timers = []
}

watch(
  () => props.active,
  (active) => {
    clearTimers()

    if (active) {
      visible.value = true
      finishing.value = false
      fading.value = false

      return
    }

    if (!visible.value) return

    finishing.value = true
    timers.push(setTimeout(() => (fading.value = true), FINISH_MS))
    timers.push(
      setTimeout(() => {
        visible.value = false
        finishing.value = false
        fading.value = false
      }, FINISH_MS + FADE_MS),
    )
  },
)

onBeforeUnmount(clearTimers)
</script>
