<template>
  <aside class="flex w-72 shrink-0 flex-col border-r border-border bg-background">
    <div class="flex h-14 shrink-0 items-center justify-between px-5">
      <h2 class="text-[15px] font-semibold">{{ $t('Shots') }}</h2>
      <div class="flex items-center gap-2">
        <span class="text-sm text-muted-foreground tabular-nums">{{ shots.length }}</span>
        <Button
          v-if="mergeUrl && shots.length > 1"
          type="button"
          :variant="merging ? 'secondary' : 'outline'"
          size="icon-sm"
          :aria-label="$t('Merge shots')"
          :aria-pressed="merging"
          :title="$t('Merge shots')"
          @click="toggleMerging"
        >
          <Combine class="size-4" />
        </Button>
        <!-- Plays the shots that have a video one after another, from the open shot onwards. -->
        <Button
          v-if="playQueue.length > 0"
          type="button"
          variant="outline"
          size="icon-sm"
          :aria-label="currentId ? $t('Play from this shot') : $t('Play all')"
          :title="currentId ? $t('Play from this shot') : $t('Play all')"
          @click="playFromCurrent"
        >
          <Play class="size-4" />
        </Button>
        <Button as-child variant="outline" size="icon-sm" :aria-label="$t('Add shot')">
          <Link :href="createUrl"><Plus class="size-4" /></Link>
        </Button>
      </div>
    </div>

    <p v-if="merging" class="px-5 pb-2 text-xs leading-relaxed text-muted-foreground">
      {{ $t('Tick shots that follow each other and have a video.') }}
    </p>

    <p v-if="shots.length === 0" class="px-4 py-6 text-xs leading-relaxed text-muted-foreground">
      {{ $t('No shots yet. Add the first one to start the sequence.') }}
    </p>

    <ol v-else ref="list" scroll-region class="flex-1 space-y-1 overflow-y-auto p-2" @scroll.passive="rememberScroll">
      <li v-for="(shot, i) in shots" :key="shot.id" :data-shot="shot.id" :class="cn('group relative', groupOutline(i))">
        <label
          v-if="merging"
          :class="
            cn(
              'flex items-center gap-3 rounded-lg border border-transparent px-2 py-2 transition-colors',
              canMerge(shot) ? 'cursor-pointer hover:bg-card' : 'cursor-not-allowed opacity-50',
              selected.includes(shot.id) && 'border-signal bg-signal-soft/60',
            )
          "
          :title="mergeBlocker(shot) ?? undefined"
        >
          <Checkbox
            :model-value="selected.includes(shot.id)"
            :disabled="!canMerge(shot)"
            @update:model-value="toggle(shot)"
          />
          <img
            v-if="shot.thumbnailUrl"
            :src="shot.thumbnailUrl"
            :alt="shot.title"
            class="h-11 w-[72px] shrink-0 rounded-md border border-border object-cover"
          />
          <Placeholder v-else class="h-11 w-[72px] shrink-0 bg-background" />
          <div class="min-w-0">
            <p class="text-[15px] font-semibold">{{ shot.code }}</p>
            <p class="truncate text-sm text-muted-foreground">{{ shot.title || $t('New shot') }}</p>
          </div>
        </label>
        <div
          v-else-if="reorderUrl"
          class="absolute top-1/2 right-2 z-10 hidden -translate-y-1/2 items-center gap-0.5 group-hover:flex"
        >
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            class="size-6 bg-background/90"
            :disabled="i === 0 || reordering"
            :aria-label="$t('Move up')"
            @click.prevent="move(i, -1)"
          >
            <ArrowUp class="size-3.5" />
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            class="size-6 bg-background/90"
            :disabled="i === shots.length - 1 || reordering"
            :aria-label="$t('Move down')"
            @click.prevent="move(i, 1)"
          >
            <ArrowDown class="size-3.5" />
          </Button>
        </div>
        <Link
          v-if="!merging"
          :href="shot.url"
          preserve-scroll
          :class="
            cn(
              'flex items-center gap-3 rounded-lg border border-transparent px-2 py-2 transition-colors hover:bg-card',
              shot.id === highlightedId && 'border-signal bg-signal-soft/60 hover:bg-signal-soft/60',
            )
          "
        >
          <span class="relative shrink-0">
            <img
              v-if="shot.thumbnailUrl"
              :src="shot.thumbnailUrl"
              :alt="shot.title"
              :class="
                cn(
                  'block h-11 w-[72px] rounded-md border border-border object-cover',
                  shot.id === highlightedId && 'border-signal',
                )
              "
            />
            <Placeholder
              v-else
              :class="cn('h-11 w-[72px] bg-background', shot.id === highlightedId && 'border-signal')"
            />
            <!-- Another shot that is still being generated; the open shot shows its own progress. -->
            <span
              v-if="shot.busy && shot.id !== currentId"
              class="absolute inset-0 flex items-center justify-center rounded-md bg-black/60"
              :title="shot.statusLabel"
            >
              <LoaderCircle class="size-4 animate-spin text-white" />
              <span class="sr-only">{{ shot.statusLabel }}</span>
            </span>
          </span>
          <div class="min-w-0">
            <p :class="cn('text-[15px] font-semibold', shot.id === highlightedId && 'text-signal')">
              {{ shot.code }}
            </p>
            <p class="truncate text-sm text-muted-foreground">
              <!-- Drawn once the place or keyframes of another shot in its sequence are there. -->
              <template v-if="shot.waitingFor">
                <Clock class="inline size-3.5 align-[-2px]" />
                {{ $t('Waits for :what', { what: shot.waitingFor }) }}
              </template>
              <template v-else-if="shot.partsCount > 0">
                {{ $t(':count shots merged', { count: String(shot.partsCount) }) }} ·
                {{ $t(':count s', { count: String(shot.duration) }) }}
              </template>
              <template v-else-if="shot.keyframesCount > 0">
                {{ $t(':count keyframes', { count: String(shot.keyframesCount) }) }} ·
                {{ $t(':count s', { count: String(shot.duration) }) }}
              </template>
              <template v-else
                >{{ shot.statusLabel }} · {{ $t(':count s', { count: String(shot.duration) }) }}</template
              >
            </p>
          </div>
        </Link>
      </li>
    </ol>

    <div v-if="merging" class="shrink-0 space-y-2 border-t border-border p-3">
      <p v-if="selectionProblem" class="text-xs text-muted-foreground">{{ selectionProblem }}</p>
      <div class="flex items-center justify-end gap-2">
        <Button type="button" variant="ghost" size="sm" @click="toggleMerging">{{ $t('Cancel') }}</Button>
        <Button type="button" size="sm" :disabled="selectionProblem !== null" @click="dialogOpen = true">
          <Combine class="size-4" />
          {{ $t('Merge :count shots', { count: String(selected.length) }) }}
        </Button>
      </div>
    </div>

    <SequencePlayer v-model:index="playing" :queue="playQueue" />
    <MergeShotsDialog
      v-if="mergeUrl"
      v-model:open="dialogOpen"
      :shots="selectedShots"
      :transitions="transitions ?? []"
      :merge-url="mergeUrl"
      @merged="toggleMerging"
    />
  </aside>
</template>
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Checkbox } from '@shared:ui/checkbox'
import { ArrowDown, ArrowUp, Clock, Combine, LoaderCircle, Play, Plus } from 'lucide-vue-next'
import { computed, nextTick, onMounted, ref, watch } from 'vue'

import MergeShotsDialog from './MergeShotsDialog.vue'
import Placeholder from './Placeholder.vue'
import SequencePlayer from './SequencePlayer.vue'
import type { ShotTransitionOption } from './TransitionPicker.vue'

export type ShotListItem = {
  id: string
  code: string
  title: string
  statusLabel: string
  duration: number
  keyframesCount: number
  /** Shots planned together in one conversation share this key. */
  groupKey?: string | null
  /** What the keyframes wait for, such as "the place of SH100". */
  waitingFor?: string | null
  /** How many shots this one was merged from; 0 for an ordinary shot. */
  partsCount: number
  status: string
  /** Storylines, keyframes or the video are being generated right now. */
  busy: boolean
  thumbnailUrl: string | null
  /** The shot's video, for playing the sequence. */
  videoUrl?: string | null
  /** The spoken track per language, played along with the video. */
  voiceOvers?: { locale: string; audioUrl: string }[]
  url: string
}

const props = defineProps<{
  shots: ShotListItem[]
  currentId?: string
  createUrl: string
  reorderUrl?: string
  mergeUrl?: string
  transitions?: ShotTransitionOption[]
}>()

/*
 * Play all: the shots with a video play one after another, and the list
 * highlights the one that plays instead of the open shot.
 */
/**
 * Shots planned together share a purple outline: the first of a run opens
 * it, the last closes it, and the gap between them is taken inside.
 */
const inGroup = (i: number, step: number) => {
  const key = props.shots[i]?.groupKey

  return Boolean(key) && props.shots[i + step]?.groupKey === key
}

const groupOutline = (i: number) => {
  if (!inGroup(i, -1) && !inGroup(i, 1)) return ''

  // The list spaces its items with a margin below each; inside a group that gap is taken as padding, so the line runs on.
  return cn(
    'border-x-2 border-signal/70 px-1 pt-1',
    inGroup(i, -1) ? '' : 'rounded-t-xl border-t-2',
    inGroup(i, 1) ? 'mb-0! pb-1' : 'rounded-b-xl border-b-2 pb-1',
  )
}

const playQueue = computed(() => props.shots.filter((shot) => shot.videoUrl))
const playing = ref<number | null>(null)
/** The first shot with a video at or after the open shot; from the start when there is none. */
const startIndex = computed(() => {
  const from = props.shots.findIndex((shot) => shot.id === props.currentId)
  const next = from < 0 ? -1 : playQueue.value.findIndex((shot) => props.shots.indexOf(shot) >= from)

  return Math.max(next, 0)
})

// The open shot's video loops on the page; it pauses so only the sequence plays.
const playFromCurrent = () => {
  document.querySelectorAll<HTMLVideoElement>('video').forEach((video) => video.pause())
  playing.value = startIndex.value
}

const highlightedId = computed(() => (playing.value === null ? props.currentId : playQueue.value[playing.value]?.id))

watch(playing, async (index) => {
  if (index === null) return
  await nextTick()
  list.value
    ?.querySelector(`[data-shot="${playQueue.value[index]?.id}"]`)
    ?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
})

const reordering = ref(false)

/*
 * Merging: the director ticks shots that follow each other and have a
 * video, then confirms a title and a transition in the dialog.
 */
const merging = ref(false)
const selected = ref<string[]>([])
const dialogOpen = ref(false)

const toggleMerging = () => {
  merging.value = !merging.value
  selected.value = []
}

const mergeBlocker = (shot: ShotListItem): string | null => {
  if (shot.partsCount > 0) return $t('Already merged. Unmerge it first.')
  if (shot.status !== 'video-ready') return $t('Render its video first.')

  return null
}

const canMerge = (shot: ShotListItem) => mergeBlocker(shot) === null

const toggle = (shot: ShotListItem) => {
  if (!canMerge(shot)) return

  selected.value = selected.value.includes(shot.id)
    ? selected.value.filter((id) => id !== shot.id)
    : [...selected.value, shot.id]
}

const selectedShots = computed(() => props.shots.filter((shot) => selected.value.includes(shot.id)))

const selectionProblem = computed<string | null>(() => {
  if (selected.value.length < 2) return $t('Tick at least two shots.')

  const indexes = props.shots.flatMap((shot, i) => (selected.value.includes(shot.id) ? [i] : []))

  return indexes[indexes.length - 1] - indexes[0] === indexes.length - 1
    ? null
    : $t('Only shots that follow each other can be merged.')
})

const move = (index: number, delta: number) => {
  if (!props.reorderUrl) {
    return
  }

  const order = props.shots.map((shot) => shot.id)
  const [moved] = order.splice(index, 1)
  order.splice(index + delta, 0, moved)

  reordering.value = true

  router.post(
    props.reorderUrl,
    { shots: order },
    {
      preserveScroll: true,
      only: ['siblings'],
      onFinish: () => {
        reordering.value = false
      },
    },
  )
}

/*
 * The list keeps its scroll position while moving between shots: the editor
 * renders it again on every visit, so the position is remembered for the tab.
 */
const SCROLL_KEY = 'aidirector.shot-list.scroll'
const list = ref<HTMLElement | null>(null)

const rememberScroll = () => {
  try {
    window.sessionStorage.setItem(SCROLL_KEY, String(list.value?.scrollTop ?? 0))
  } catch {
    // Storage can be blocked; the list then starts at the top.
  }
}

onMounted(() => {
  try {
    const top = Number(window.sessionStorage.getItem(SCROLL_KEY) ?? 0)

    if (list.value && top > 0) list.value.scrollTop = top
  } catch {
    // Storage can be blocked; the list then starts at the top.
  }
})
</script>
