<template>
  <ul class="space-y-3">
    <li v-for="track in tracks" :key="track.locale" class="space-y-1.5">
      <p class="flex items-center gap-2 text-sm">
        <span aria-hidden="true" class="text-base leading-none">{{ flag(track.locale) }}</span>
        <span class="font-medium">{{ track.locale }}</span>
        <span class="truncate text-muted-foreground">{{ track.name }}</span>
        <span
          v-if="track.outdated && track.status !== 'pending'"
          class="ml-auto shrink-0 rounded-full border border-amber-500/50 bg-amber-500/10 px-2 py-0.5 text-xs"
        >
          {{ $t('Older text') }}
        </span>
      </p>
      <p v-if="track.status === 'pending'" class="flex items-center gap-2 text-xs text-signal">
        <LoaderCircle class="size-3.5 animate-spin" />
        {{ $t('Speaking…') }}
      </p>
      <p v-else-if="track.status === 'failed'" class="text-xs text-destructive">
        {{ track.error ?? $t('This language could not be spoken.') }}
      </p>
      <audio
        v-else-if="track.audioUrl"
        :src="track.audioUrl"
        controls
        preload="none"
        controlslist="nodownload noplaybackrate noremoteplayback"
        disableremoteplayback
        class="voice-track h-8 w-full"
        @play="playWithVideo"
      />
      <p v-else class="text-xs text-muted-foreground">{{ emptyHint }}</p>
    </li>
  </ul>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { LoaderCircle } from 'lucide-vue-next'

export type AudioTrack = {
  locale: string
  name: string
  status: string
  audioUrl: string | null
  outdated: boolean
  error: string | null
}

defineProps<{
  tracks: AudioTrack[]
  /** Shown for a language that has no track yet. */
  emptyHint: string
}>()

/** The flag of the locale's country, from its region code: "nl-NL" becomes 🇳🇱. */
const flag = (code: string) =>
  (code.split('-')[1] ?? '')
    .toUpperCase()
    .replace(/./g, (letter) => String.fromCodePoint(127397 + letter.charCodeAt(0)))

/**
 * Starting a language plays it over the shot from the beginning: the video
 * restarts with it, and any other language that was playing stops.
 */
const playWithVideo = (event: Event) => {
  const audio = event.target as HTMLAudioElement

  document.querySelectorAll('audio').forEach((other) => {
    if (other !== audio) other.pause()
  })

  if (audio.currentTime > 0.3) audio.currentTime = 0

  const video = document.querySelector<HTMLVideoElement>('video[data-shot-video]')

  if (video) {
    video.currentTime = 0
    void video.play().catch(() => undefined)
  }
}
</script>
