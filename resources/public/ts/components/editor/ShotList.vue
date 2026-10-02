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

    <ol v-else class="flex-1 space-y-1 overflow-y-auto p-2">
      <li v-for="(shot, i) in shots" :key="shot.id" class="group relative">
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
            <p class="truncate text-sm text-muted-foreground">{{ shot.title }}</p>
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
          :class="
            cn(
              'flex items-center gap-3 rounded-lg border border-transparent px-2 py-2 transition-colors hover:bg-card',
              shot.id === currentId && 'border-signal bg-signal-soft/60 hover:bg-signal-soft/60',
            )
          "
        >
          <img
            v-if="shot.thumbnailUrl"
            :src="shot.thumbnailUrl"
            :alt="shot.title"
            :class="
              cn(
                'h-11 w-[72px] shrink-0 rounded-md border border-border object-cover',
                shot.id === currentId && 'border-signal',
              )
            "
          />
          <Placeholder
            v-else
            :class="cn('h-11 w-[72px] shrink-0 bg-background', shot.id === currentId && 'border-signal')"
          />
          <div class="min-w-0">
            <p :class="cn('text-[15px] font-semibold', shot.id === currentId && 'text-signal')">
              {{ shot.code }}
            </p>
            <p class="truncate text-sm text-muted-foreground">
              <template v-if="shot.partsCount > 0">
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
import { ArrowDown, ArrowUp, Combine, Plus } from 'lucide-vue-next'
import { computed, ref } from 'vue'

import MergeShotsDialog from './MergeShotsDialog.vue'
import Placeholder from './Placeholder.vue'
import type { ShotTransitionOption } from './TransitionPicker.vue'

export type ShotListItem = {
  id: string
  code: string
  title: string
  statusLabel: string
  duration: number
  keyframesCount: number
  /** How many shots this one was merged from; 0 for an ordinary shot. */
  partsCount: number
  status: string
  thumbnailUrl: string | null
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
</script>
