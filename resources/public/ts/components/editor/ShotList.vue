<template>
  <aside class="flex w-72 shrink-0 flex-col border-r border-border bg-background">
    <div class="flex h-14 shrink-0 items-center justify-between px-5">
      <h2 class="text-[15px] font-semibold">{{ $t('Shots') }}</h2>
      <div class="flex items-center gap-2">
        <span class="text-sm text-muted-foreground tabular-nums">{{ shots.length }}</span>
        <Button
          v-if="(mergeUrl || destroyUrl) && shots.length > 0"
          type="button"
          :variant="editing ? 'secondary' : 'outline'"
          size="icon-sm"
          :aria-label="$t('Edit shots')"
          :aria-pressed="editing"
          :title="$t('Edit shots')"
          @click="toggleEditing"
        >
          <SquarePen class="size-4" />
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

    <p v-if="editing" class="px-5 pb-2 text-xs leading-relaxed text-muted-foreground">
      {{
        $t(
          'Tick shots to delete them, or to merge shots that follow each other and have a video, or their start frame when they render as a sequence.',
        )
      }}
    </p>

    <p v-if="shots.length === 0" class="px-4 py-6 text-xs leading-relaxed text-muted-foreground">
      {{ $t('No shots yet. Add the first one to start the sequence.') }}
    </p>

    <ol v-else ref="list" scroll-region class="flex-1 space-y-1 overflow-y-auto p-2" @scroll.passive="rememberScroll">
      <li
        v-for="(shot, i) in shots"
        :key="shot.id"
        :data-shot="shot.id"
        :draggable="canDrag"
        :class="cn('group relative', groupOutline(i), tab(i) && 'mt-7', dragged === shot.id && 'opacity-40')"
        @dragstart="startDrag($event, shot)"
        @dragend="endDrag"
        @dragover.prevent="hover($event, i)"
        @drop.prevent="drop"
      >
        <!-- Where the dragged shot lands: inside a group the line stays within its outline, between groups it runs the full width. -->
        <span
          v-if="target?.kind === 'between' && lineAt(i)"
          :class="
            cn(
              'pointer-events-none absolute z-20 h-0.5 rounded-full bg-current',
              lineAt(i) === 'top' ? '-top-[3px]' : '-bottom-[3px]',
              target.inside ? 'inset-x-4 text-signal' : '-inset-x-1 text-foreground/80',
            )
          "
        >
          <!-- A small triangle at each end, pointing inwards to where the shot lands. -->
          <span
            class="absolute top-1/2 -left-1 size-0 -translate-y-1/2 border-y-[5px] border-l-[6px] border-y-transparent border-l-current"
          />
          <span
            class="absolute top-1/2 -right-1 size-0 -translate-y-1/2 border-y-[5px] border-r-[6px] border-y-transparent border-r-current"
          />
          <span
            v-if="target.inside"
            class="absolute -top-2.5 right-0 rounded bg-signal px-1.5 text-[10px] leading-4 font-medium text-white"
          >
            {{ $t('In group') }}
          </span>
        </span>
        <!-- On the border of the open shot's group: merges the group, or splits a merged shot again. -->
        <button
          v-if="tab(i) === 'merge'"
          type="button"
          :class="tabClass"
          :disabled="!groupShots(i).every(canMerge)"
          :title="groupShots(i).every(canMerge) ? $t('Merge these shots') : mergeNeeds(groupShots(i))"
          @click="mergeGroup(i)"
        >
          <Combine class="size-3.5" />
          {{ $t('Merge') }}
        </button>
        <ConfirmDelete
          v-else-if="tab(i) === 'unmerge' && shot.unmergeUrl"
          :action="shot.unmergeUrl"
          :title="$t('Unmerge this shot?')"
          :description="
            $t('The :count shots it was made from come back in its place, as they were. The joined video is removed.', {
              count: String(shot.partsCount),
            })
          "
          :confirm-label="$t('Unmerge')"
        >
          <template #trigger>
            <button type="button" :class="tabClass">
              <Split class="size-3.5" />
              {{ $t('Unmerge') }}
            </button>
          </template>
        </ConfirmDelete>
        <label
          v-if="editing"
          :class="
            cn(
              'flex cursor-pointer items-center gap-3 rounded-lg border border-transparent px-2 py-2 transition-colors hover:bg-card',
              selected.includes(shot.id) && 'border-signal bg-signal-soft/60',
            )
          "
        >
          <Checkbox :model-value="selected.includes(shot.id)" @update:model-value="toggle(shot)" />
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
        <Link
          v-if="!editing"
          :href="shot.url"
          preserve-scroll
          draggable="false"
          :class="
            cn(
              'relative flex items-center gap-3 px-2 py-2 transition-colors hover:bg-card',
              rowShape(i),
              canDrag && 'cursor-grab',
              shot.id === highlightedId && 'bg-signal-soft/60 hover:bg-signal-soft/60',
              shot.id === highlightedId && !inGroup(i, -1) && !inGroup(i, 1) && 'border-signal',
              target?.kind === 'onto' && target.id === shot.id && 'border-signal bg-signal-soft ring-2 ring-signal/50',
            )
          "
        >
          <!-- Dropped on top: the two form a group, or the dragged shot joins this one's group. -->
          <span
            v-if="target?.kind === 'onto' && target.id === shot.id"
            class="pointer-events-none absolute top-1 right-1.5 rounded bg-signal px-1.5 text-[10px] leading-4 font-medium text-white"
          >
            {{
              sameGroup(dragged, shot.id)
                ? $t('Move here')
                : shot.groupKey && groupShots(i).length > 1
                  ? $t('Add to group')
                  : $t('Make a group')
            }}
          </span>
          <span class="relative shrink-0">
            <img
              v-if="shot.thumbnailUrl"
              :src="shot.thumbnailUrl"
              :alt="shot.title"
              draggable="false"
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
              <template v-else
                >{{ shot.statusLabel }} · {{ $t(':count s', { count: String(shot.duration) }) }}</template
              >
            </p>
          </div>
        </Link>
      </li>
    </ol>

    <div v-if="editing" class="flex shrink-0 flex-wrap items-center justify-end gap-2 border-t border-border p-3">
      <Button type="button" variant="ghost" size="sm" class="mr-auto" @click="toggleEditing">{{ $t('Done') }}</Button>
      <ConfirmDelete
        v-if="destroyUrl && selected.length > 0"
        :action="destroyUrl"
        :data="{ shots: selected, current: currentId ?? null }"
        :title="
          selected.length === 1
            ? $t('Delete this shot?')
            : $t('Delete :count shots?', { count: String(selected.length) })
        "
        :description="
          $t(
            'The shots are removed from the sequence with their storylines, keyframes, videos and audio tracks. This cannot be undone.',
          )
        "
        :confirm-label="$t('Delete')"
        @deleted="toggleEditing"
      >
        <template #trigger>
          <Button type="button" variant="outline" size="sm" class="text-destructive hover:text-destructive">
            <Trash2 class="size-4" />
            {{ $t('Delete :count', { count: String(selected.length) }) }}
          </Button>
        </template>
      </ConfirmDelete>
      <Button v-if="mergeUrl && canMergeSelection" type="button" size="sm" @click="dialogOpen = true">
        <Combine class="size-4" />
        {{ $t('Merge :count', { count: String(selected.length) }) }}
      </Button>
    </div>

    <SequencePlayer v-model:index="playing" :queue="playQueue" />
    <MergeShotsDialog
      v-if="mergeUrl"
      v-model:open="dialogOpen"
      :shots="editing ? selectedShots : mergingGroup"
      :transitions="transitions ?? []"
      :merge-url="mergeUrl"
      @merged="stopEditing"
    />
  </aside>
</template>
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import ConfirmDelete from '@public:components/ConfirmDelete.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Checkbox } from '@shared:ui/checkbox'
import { Clock, Combine, LoaderCircle, Play, Plus, Split, SquarePen, Trash2 } from 'lucide-vue-next'
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
  /** Shots planned together in one conversation share this key. */
  groupKey?: string | null
  /** What the keyframes wait for, such as "the place of SH100". */
  waitingFor?: string | null
  /** How many shots this one was merged from; 0 for an ordinary shot. */
  partsCount: number
  /** The shot renders with its whole sequence in one clip, so it merges once its start frame is drawn. */
  rendersInSequence?: boolean
  status: string
  /** Storylines, keyframes or the video are being generated right now. */
  busy: boolean
  thumbnailUrl: string | null
  /** The shot's video, for playing the sequence. */
  videoUrl?: string | null
  /** The spoken track per language, played along with the video. */
  voiceOvers?: { locale: string; audioUrl: string }[]
  url: string
  /** Splits a merged shot back into its parts. */
  unmergeUrl?: string | null
}

const props = defineProps<{
  shots: ShotListItem[]
  currentId?: string
  createUrl: string
  reorderUrl?: string
  mergeUrl?: string
  /** Deletes the ticked shots. */
  destroyUrl?: string
  transitions?: ShotTransitionOption[]
}>()

/*
 * Play all: the shots with a video play one after another, and the list
 * highlights the one that plays instead of the open shot.
 */
/**
 * Shots in a group share an outline, purple for the open shot's group: the
 * first of a run opens it, the last closes it, and the gap between them is
 * taken inside.
 */
const inGroup = (i: number, step: number) => {
  const key = props.shots[i]?.groupKey

  return Boolean(key) && props.shots[i + step]?.groupKey === key
}

const groupOutline = (i: number) => {
  // Every group is outlined, so a group shows as soon as it is formed; the open shot's group stands out.
  const line = groupShots(i).some((shot) => shot.id === props.currentId) ? 'border-signal/70' : 'border-border'

  // A merged shot is outlined on its own.
  if (!inGroup(i, -1) && !inGroup(i, 1)) return props.shots[i]?.partsCount ? cn('rounded-xl border-2 p-1', line) : ''

  // The shots sit against the outline; the list's gap below each is left out inside a group, so the line runs on.
  return cn(
    'border-x-2',
    line,
    inGroup(i, -1) ? '' : 'rounded-t-xl border-t-2',
    inGroup(i, 1) ? 'mb-0!' : 'rounded-b-xl border-b-2',
  )
}

/**
 * A shot on its own has a rounded border of its own; inside a group the
 * shots are parted by a line, and the first and last follow the outline's
 * corners, so a selected shot's fill fits inside it.
 */
const rowShape = (i: number) => {
  if (!inGroup(i, -1) && !inGroup(i, 1)) return 'rounded-lg border border-transparent'

  return cn(inGroup(i, -1) ? '' : 'rounded-t-[10px]', inGroup(i, 1) ? 'border-b border-border' : 'rounded-b-[10px]')
}

/** The shots of the group that shot i is in. */
const groupShots = (i: number) => {
  let start = i
  let end = i

  while (inGroup(start, -1)) start--
  while (inGroup(end, 1)) end++

  return props.shots.slice(start, end + 1)
}

/** The tab standing on the top border of the open shot's group: merge for a group, unmerge for a merged shot. */
const tab = (i: number): 'merge' | 'unmerge' | null => {
  const shot = props.shots[i]

  if (editing.value || !shot) return null
  if (shot.unmergeUrl && shot.id === props.currentId) return 'unmerge'
  if (!props.mergeUrl || inGroup(i, -1) || !inGroup(i, 1)) return null

  return groupShots(i).some((member) => member.id === props.currentId) ? 'merge' : null
}

const tabClass =
  'absolute top-0 right-3 -mt-0.5 inline-flex h-6 -translate-y-full items-center gap-1 z-10 rounded-t-md bg-[color-mix(in_oklab,var(--color-signal)_70%,var(--color-background))] px-2.5 text-xs font-medium text-white transition-colors hover:bg-signal disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-[color-mix(in_oklab,var(--color-signal)_70%,var(--color-background))]'

const mergingGroup = ref<ShotListItem[]>([])

const mergeGroup = (i: number) => {
  mergingGroup.value = groupShots(i)
  dialogOpen.value = true
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
const editing = ref(false)
const selected = ref<string[]>([])
const dialogOpen = ref(false)

const toggleEditing = () => {
  editing.value = !editing.value
  selected.value = []
}

const stopEditing = () => {
  editing.value = false
  selected.value = []
}

/** A shot can be merged when it has a video, or its start frame when it renders with its sequence, and is not a merged shot itself. */
const canMerge = (shot: ShotListItem) =>
  shot.partsCount === 0 &&
  (shot.status === 'video-ready' ||
    (shot.rendersInSequence && ['keyframes-ready', 'video-pending'].includes(shot.status)))

/** What the shots of a group still need before they can be merged. */
const mergeNeeds = (shots: ShotListItem[]) =>
  shots.every((shot) => shot.rendersInSequence)
    ? $t('Every shot needs its start frame first.')
    : $t('Every shot needs a video first.')

const toggle = (shot: ShotListItem) => {
  selected.value = selected.value.includes(shot.id)
    ? selected.value.filter((id) => id !== shot.id)
    : [...selected.value, shot.id]
}

const selectedShots = computed(() => props.shots.filter((shot) => selected.value.includes(shot.id)))

/** Merge is offered for two or more ticked shots that follow each other and all have a video. */
const canMergeSelection = computed(() => {
  if (selectedShots.value.length < 2 || !selectedShots.value.every(canMerge)) return false

  const indexes = props.shots.flatMap((shot, i) => (selected.value.includes(shot.id) ? [i] : []))

  return indexes[indexes.length - 1] - indexes[0] === indexes.length - 1
})

/*
 * Dragging: a shot dropped between two others lands there, inside the
 * group when both neighbours are in it and on its own otherwise; a shot
 * dropped on top of another joins that shot's group, or forms one with it.
 * Every shot is a group of its own until it is grouped.
 */
type DropTarget = { kind: 'onto'; id: string } | { kind: 'between'; index: number; inside: boolean }

const dragged = ref<string | null>(null)
const target = ref<DropTarget | null>(null)

const canDrag = computed(() => Boolean(props.reorderUrl) && !editing.value && !reordering.value)

/** The shots in order as groups of ids: shots next to each other with the same key form one. */
const currentGroups = () =>
  props.shots.reduce<string[][]>((groups, shot, i) => {
    if (inGroup(i, -1)) groups[groups.length - 1].push(shot.id)
    else groups.push([shot.id])

    return groups
  }, [])

/** The nearest shot before or from a place in the list, leaving out the one that is dragged. */
const neighbour = (from: number, step: -1 | 1, moving: string) => {
  for (let i = from; i >= 0 && i < props.shots.length; i += step) {
    if (props.shots[i].id !== moving) return props.shots[i]
  }

  return undefined
}

/** Whether a line between the shots at index - 1 and index falls inside one group. */
const insideAt = (index: number, moving: string) => {
  const before = neighbour(index - 1, -1, moving)
  const after = neighbour(index, 1, moving)

  return Boolean(before?.groupKey) && before?.groupKey === after?.groupKey
}

/** Whether two shots are in the same group of two or more. */
const sameGroup = (one: string | null, other: string) =>
  one !== null && one !== other && currentGroups().some((group) => group.includes(one) && group.includes(other))

/**
 * The groups after the shot is placed: between two shots, or on top of one.
 * On top of a shot of its own group it takes that shot's place, so a group
 * is put in order by dropping its shots on each other.
 */
const placed = (moving: string, drop: DropTarget): string[][] => {
  const order = props.shots.map((shot) => shot.id)
  const takesPlace =
    drop.kind === 'onto' && sameGroup(moving, drop.id) && order.indexOf(drop.id) < order.indexOf(moving)
  const groups = currentGroups()
    .map((group) => group.filter((id) => id !== moving))
    .filter((group) => group.length > 0)

  if (drop.kind === 'onto') {
    const group = groups.find((members) => members.includes(drop.id))
    group?.splice(group.indexOf(drop.id) + (takesPlace ? 0 : 1), 0, moving)

    return groups
  }

  const after = neighbour(drop.index, 1, moving)

  if (!after) return [...groups, [moving]]

  const at = groups.findIndex((members) => members.includes(after.id))

  if (drop.inside) groups[at].splice(groups[at].indexOf(after.id), 0, moving)
  else groups.splice(at, 0, [moving])

  return groups
}

const save = (groups: string[][]) => {
  if (!props.reorderUrl || JSON.stringify(groups) === JSON.stringify(currentGroups())) return

  reordering.value = true

  router.post(
    props.reorderUrl,
    { groups },
    {
      preserveScroll: true,
      only: ['siblings'],
      onFinish: () => {
        reordering.value = false
      },
    },
  )
}

const startDrag = (event: DragEvent, shot: ShotListItem) => {
  if (!canDrag.value) return

  dragged.value = shot.id
  event.dataTransfer?.setData('text/plain', shot.id)
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

const endDrag = () => {
  dragged.value = null
  target.value = null
}

/** The top and bottom quarter of a shot drop between shots; the middle drops on top of it. */
const hover = (event: DragEvent, i: number) => {
  const moving = dragged.value

  if (!moving) return

  const box = (event.currentTarget as HTMLElement).getBoundingClientRect()
  const ratio = (event.clientY - box.top) / box.height

  if (ratio > 0.25 && ratio < 0.75) {
    target.value = props.shots[i].id === moving ? null : { kind: 'onto', id: props.shots[i].id }

    return
  }

  const index = ratio <= 0.25 ? i : i + 1
  target.value = { kind: 'between', index, inside: insideAt(index, moving) }
}

/** Which edge of shot i shows the line for a drop between shots. */
const lineAt = (i: number): 'top' | 'bottom' | null => {
  if (target.value?.kind !== 'between') return null
  if (target.value.index === i) return 'top'

  return target.value.index === props.shots.length && i === props.shots.length - 1 ? 'bottom' : null
}

const drop = () => {
  if (dragged.value && target.value) save(placed(dragged.value, target.value))

  endDrag()
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
