<template>
  <div class="flex min-w-0 flex-1">
    <aside class="flex w-[22rem] shrink-0 flex-col gap-6 overflow-y-auto border-r border-border p-6">
      <div class="flex items-start justify-between gap-4">
        <h2 class="text-2xl leading-tight font-semibold text-balance">{{ title }}</h2>
        <ConfirmDelete
          :action="merge.links.unmerge"
          :title="$t('Unmerge this shot?')"
          :description="
            $t('The :count shots it was made from come back in its place, as they were. The joined video is removed.', {
              count: String(merge.parts.length),
            })
          "
          :confirm-label="$t('Unmerge')"
        >
          <template #trigger>
            <Button type="button" variant="outline" size="sm">
              <Split class="size-4" />
              {{ $t('Unmerge') }}
            </Button>
          </template>
        </ConfirmDelete>
      </div>

      <section class="space-y-3">
        <h3 class="flex items-center gap-2 font-semibold">
          <Combine class="size-4 text-muted-foreground" />
          {{ $t('Merged from :count shots', { count: String(merge.parts.length) }) }}
        </h3>
        <ol class="space-y-3">
          <li v-for="(part, i) in merge.parts" :key="part.id">
            <Link
              :href="part.url"
              class="group flex gap-3 rounded-lg border border-border p-2 transition-colors hover:border-muted-foreground/60"
            >
              <img
                v-if="part.thumbnailUrl"
                :src="part.thumbnailUrl"
                :alt="part.title"
                class="h-14 w-20 shrink-0 rounded-md border border-border object-cover"
              />
              <Placeholder v-else class="h-14 w-20 shrink-0 bg-background" />
              <span class="min-w-0 space-y-0.5">
                <span class="block text-xs text-muted-foreground tabular-nums">
                  {{ $t('Part :n', { n: String(i + 1) }) }} · {{ $t(':count s', { count: String(part.duration) }) }}
                </span>
                <span class="block truncate text-sm font-medium group-hover:text-signal">{{ part.title }}</span>
              </span>
            </Link>
          </li>
        </ol>
        <p class="text-xs leading-relaxed text-muted-foreground">
          {{ $t('Open a part to change its keyframes or render its video again; the merged video follows.') }}
        </p>
      </section>

      <section v-if="takeaways.length > 0" class="space-y-2 border-t border-border pt-6">
        <h3 class="flex items-center gap-2 font-semibold">
          <Target class="size-4 text-muted-foreground" />
          {{ $t('Takeaway') }}
        </h3>
        <p class="text-[15px] leading-relaxed">{{ takeaways.join(' ') }}</p>
      </section>

      <section v-if="voiceOverTracks.length > 0" class="space-y-3 border-t border-border pt-6">
        <h3 class="flex items-center gap-2 font-semibold">
          <AudioLines class="size-4 text-muted-foreground" />
          {{ $t('Audio track') }}
        </h3>
        <AudioTracks
          :tracks="voiceOverTracks"
          :empty-hint="$t('Made from the parts\' tracks once the clips are joined.')"
        />
      </section>
    </aside>

    <section class="group relative flex min-h-0 min-w-0 flex-1 flex-col gap-4 p-6">
      <div
        v-if="error && !pending"
        class="flex items-center justify-between gap-4 rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm"
      >
        <p>{{ error }}</p>
        <Button type="button" variant="outline" size="sm" :disabled="join.processing" @click="joinAgain">
          <RefreshCw class="size-4" :class="join.processing && 'animate-spin'" />
          {{ $t('Try again') }}
        </Button>
      </div>

      <!-- In the top right corner of the video area. -->
      <VideoActions v-if="videoUrl && !pending" :download-url="downloadUrl" class="absolute top-4 right-4 z-10" />

      <div class="flex min-h-0 flex-1 items-center justify-center">
        <video
          v-if="videoUrl && !pending"
          :key="videoUrl"
          data-shot-video
          :src="videoUrl"
          controls
          autoplay
          muted
          loop
          playsinline
          class="max-h-full max-w-full rounded-xl border border-border bg-black object-cover"
          :style="frameSize"
        />
        <Placeholder v-else class="max-h-full max-w-full rounded-xl bg-card" :style="frameSize">
          <p
            v-if="pending"
            class="flex items-center gap-2 rounded-lg border border-border bg-background/90 px-4 py-3 text-sm text-signal"
          >
            <LoaderCircle class="size-4 animate-spin" />
            {{ $t('Joining the clips…') }}
          </p>
        </Placeholder>
      </div>

      <div class="flex shrink-0 flex-wrap items-end justify-between gap-4 border-t border-border pt-4">
        <div class="min-w-0 flex-1 space-y-2">
          <p class="text-sm font-medium">{{ $t('Between the clips') }}</p>
          <TransitionPicker v-model="join.transition" :transitions="merge.transitions" class="grid-cols-3" />
        </div>
        <Button
          type="button"
          :disabled="pending || join.processing || join.transition === merge.transition"
          @click="joinAgain"
        >
          <Combine class="size-4" />
          {{ $t('Join again') }}
        </Button>
      </div>
    </section>
  </div>
</template>
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import ConfirmDelete from '@public:components/ConfirmDelete.vue'
import { Button } from '@shared:ui/button'
import { AudioLines, Combine, LoaderCircle, RefreshCw, Split, Target } from 'lucide-vue-next'
import { computed, watch } from 'vue'

import AudioTracks, { type AudioTrack } from './AudioTracks.vue'
import Placeholder from './Placeholder.vue'
import TransitionPicker, { type ShotTransitionOption } from './TransitionPicker.vue'
import VideoActions from './VideoActions.vue'

export type ShotMerge = {
  transition: string
  transitions: ShotTransitionOption[]
  parts: {
    id: string
    title: string
    takeaway: string
    storyline: string | null
    duration: number
    thumbnailUrl: string | null
    videoUrl: string | null
    url: string
  }[]
  links: { update: string; unmerge: string }
}

const props = defineProps<{
  title: string
  takeaway: string | null
  merge: ShotMerge
  videoUrl: string | null
  downloadUrl?: string | null
  error: string | null
  pending: boolean
  aspectRatio: string
  voiceOverTracks: AudioTrack[]
}>()

/**
 * The takeaway of every part, in order; the merged shot itself has none of its own.
 */
const takeaways = computed(() => props.merge.parts.map((part) => part.takeaway).filter((line) => line.trim() !== ''))

const join = useForm({ transition: props.merge.transition })

watch(
  () => props.merge.transition,
  (transition) => {
    join.transition = transition
  },
)

const joinAgain = () => join.post(props.merge.links.update, { preserveScroll: true })

/**
 * One side fills the stage and the ratio sets the other, so the frame hugs the video.
 */
const frameSize = computed(() => {
  const [w, h] = props.aspectRatio.split(':').map(Number)

  return {
    aspectRatio: `${w} / ${h}`,
    height: h >= w ? '100%' : undefined,
    width: h >= w ? undefined : '100%',
  }
})
</script>
