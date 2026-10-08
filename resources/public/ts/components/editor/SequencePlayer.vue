<template>
  <Dialog :open="index !== null" @update:open="(open) => !open && stop()">
    <DialogContent class="max-w-3xl">
      <DialogHeader>
        <DialogTitle>{{ current ? `${current.code} · ${current.title}` : $t('Play all') }}</DialogTitle>
        <DialogDescription>
          {{ $t('Shot :n of :total with a video', { n: String((index ?? 0) + 1), total: String(queue.length) }) }}
        </DialogDescription>
      </DialogHeader>
      <!-- The spoken track of the chosen language follows the video: play, pause and seeking. -->
      <NativeSelect v-if="locales.length > 1" v-model="locale" class="w-48" :aria-label="$t('Language')">
        <option v-for="option in locales" :key="option" :value="option">{{ option }}</option>
      </NativeSelect>
      <video
        v-if="current"
        :key="current.id"
        ref="video"
        :src="current.videoUrl ?? undefined"
        controls
        autoplay
        playsinline
        class="max-h-[70vh] w-full rounded-lg bg-black object-contain"
        @play="syncAudio(true)"
        @pause="syncAudio(false)"
        @seeked="syncAudio(!video?.paused)"
        @ended="next"
      />
      <audio v-if="audioUrl" :key="`${current?.id}-${locale}`" ref="audio" :src="audioUrl" preload="auto" />
      <DialogFooter class="flex-row justify-between sm:justify-between">
        <Button type="button" variant="outline" :disabled="(index ?? 0) === 0" @click="previous">
          <SkipBack class="size-4" />
          {{ $t('Previous') }}
        </Button>
        <Button type="button" variant="outline" :disabled="(index ?? 0) >= queue.length - 1" @click="next">
          {{ $t('Next') }}
          <SkipForward class="size-4" />
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { Button } from '@shared:ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@shared:ui/dialog'
import { NativeSelect } from '@shared:ui/native-select'
import { SkipBack, SkipForward } from 'lucide-vue-next'
import { computed, ref, useTemplateRef, watch } from 'vue'

import type { ShotListItem } from './ShotList.vue'

const props = defineProps<{
  /** The shots with a video, in sequence order. */
  queue: ShotListItem[]
}>()

/** Which of the queue plays; null while the player is closed. */
const index = defineModel<number | null>('index', { default: null })

const current = computed(() => (index.value === null ? null : (props.queue[index.value] ?? null)))

/** The languages any shot in the sequence has a spoken track in; the first is played by default. */
const locales = computed(() => [
  ...new Set(props.queue.flatMap((shot) => (shot.voiceOvers ?? []).map((track) => track.locale))),
])
const locale = ref<string | null>(null)

watch(
  locales,
  (available) => {
    if (!locale.value || !available.includes(locale.value)) locale.value = available[0] ?? null
  },
  { immediate: true },
)

const audioUrl = computed(
  () => current.value?.voiceOvers?.find((track) => track.locale === locale.value)?.audioUrl ?? null,
)

const video = useTemplateRef<HTMLVideoElement>('video')
const audio = useTemplateRef<HTMLAudioElement>('audio')

/** Keeps the spoken track at the video's time; it plays and pauses with it. */
const syncAudio = (playing: boolean) => {
  if (!audio.value || !video.value) return

  audio.value.currentTime = video.value.currentTime

  if (playing) {
    void audio.value.play().catch(() => {})
  } else {
    audio.value.pause()
  }
}

// Another language while a shot plays picks up at the same moment.
watch(audio, (element) => {
  if (element && video.value && !video.value.paused) syncAudio(true)
})

// After the last shot the player closes by itself.
const next = () => {
  if (index.value === null) return
  index.value = index.value < props.queue.length - 1 ? index.value + 1 : null
}

const previous = () => {
  if (index.value !== null && index.value > 0) index.value -= 1
}

const stop = () => {
  index.value = null
}
</script>
