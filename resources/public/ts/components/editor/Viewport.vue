<template>
  <section class="flex min-h-0 flex-1 flex-col gap-5 p-6">
    <div class="flex min-h-0 flex-1 items-center justify-center">
      <Placeholder
        class="max-h-full max-w-full rounded-xl bg-card"
        :style="{
          aspectRatio: aspectRatio.replace(':', ' / '),
          height: isPortrait ? '100%' : undefined,
          width: isPortrait ? undefined : '100%',
        }"
      >
        <div
          v-if="selected"
          class="max-w-md space-y-2 rounded-lg border border-border bg-background/90 px-5 py-4 text-center"
        >
          <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {{ $t('Keyframe :n of :total', { n: String(selected.position), total: String(keyframes.length) }) }}
          </p>
          <h3 class="font-semibold">{{ selected.title }}</h3>
          <p class="text-sm leading-relaxed text-muted-foreground">{{ selected.description }}</p>
        </div>
        <p v-else class="rounded-md border border-border bg-background/80 px-3 py-1.5 text-xs text-muted-foreground">
          {{ $t('Keyframe preview · :ratio', { ratio: aspectRatio }) }}
        </p>
      </Placeholder>
    </div>

    <ul class="flex shrink-0 items-stretch gap-3 overflow-x-auto">
      <li v-for="keyframe in keyframes" :key="keyframe.id">
        <button
          type="button"
          :class="
            cn(
              'flex h-32 w-40 flex-col overflow-hidden rounded-lg border border-border bg-card text-left transition-colors hover:border-muted-foreground/60',
              keyframe.id === selectedId && 'border-signal ring-2 ring-signal/40',
            )
          "
          @click="emit('select', keyframe.id)"
        >
          <Placeholder class="min-h-0 flex-1 rounded-none border-0 bg-card" />
          <span class="flex items-center justify-between gap-2 px-3 py-2 text-xs">
            <span class="truncate font-semibold">{{ keyframe.title }}</span>
            <span class="shrink-0 text-muted-foreground tabular-nums">{{
              String(keyframe.position).padStart(2, '0')
            }}</span>
          </span>
        </button>
      </li>
      <li
        class="flex h-32 w-32 shrink-0 items-center justify-center rounded-lg border border-dashed border-border bg-card/60 text-muted-foreground"
        :title="$t('Images arrive in the next phase')"
      >
        <Plus class="size-6" />
      </li>
    </ul>
  </section>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { Plus } from 'lucide-vue-next'
import { computed } from 'vue'

import Placeholder from './Placeholder.vue'

export type ViewportKeyframe = { id: string; position: number; title: string; description: string }

const props = defineProps<{
  aspectRatio: string
  keyframes: ViewportKeyframe[]
  selectedId?: string
}>()

const emit = defineEmits<{ select: [id: string] }>()

const isPortrait = computed(() => {
  const [w, h] = props.aspectRatio.split(':').map(Number)

  return h >= w
})

const selected = computed(() => props.keyframes.find((keyframe) => keyframe.id === props.selectedId))
</script>
