<template>
  <aside class="flex w-72 shrink-0 flex-col border-r border-border bg-background">
    <div class="flex h-14 shrink-0 items-center justify-between px-5">
      <h2 class="text-[15px] font-semibold">{{ $t('Shots') }}</h2>
      <div class="flex items-center gap-2">
        <span class="text-sm text-muted-foreground tabular-nums">{{ shots.length }}</span>
        <Button as-child variant="outline" size="icon-sm" :aria-label="$t('Add shot')">
          <Link :href="createUrl"><Plus class="size-4" /></Link>
        </Button>
      </div>
    </div>

    <p v-if="shots.length === 0" class="px-4 py-6 text-xs leading-relaxed text-muted-foreground">
      {{ $t('No shots yet. Add the first one to start the sequence.') }}
    </p>

    <ol v-else class="flex-1 space-y-1 overflow-y-auto p-2">
      <li v-for="(shot, i) in shots" :key="shot.id" class="group relative">
        <div
          v-if="reorderUrl"
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
          :href="shot.url"
          :class="
            cn(
              'flex items-center gap-3 rounded-lg border border-transparent px-2 py-2 transition-colors hover:bg-card',
              shot.id === currentId && 'border-signal bg-signal-soft/60 hover:bg-signal-soft/60',
            )
          "
        >
          <Placeholder :class="cn('h-11 w-[72px] shrink-0 bg-background', shot.id === currentId && 'border-signal')" />
          <div class="min-w-0">
            <p :class="cn('text-[15px] font-semibold', shot.id === currentId && 'text-signal')">
              {{ shot.code }}
            </p>
            <p class="truncate text-sm text-muted-foreground">
              <template v-if="shot.keyframesCount > 0">
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
  </aside>
</template>
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { ArrowDown, ArrowUp, Plus } from 'lucide-vue-next'
import { ref } from 'vue'

import Placeholder from './Placeholder.vue'

export type ShotListItem = {
  id: string
  code: string
  title: string
  statusLabel: string
  duration: number
  keyframesCount: number
  url: string
}

const props = defineProps<{
  shots: ShotListItem[]
  currentId?: string
  createUrl: string
  reorderUrl?: string
}>()

const reordering = ref(false)

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
