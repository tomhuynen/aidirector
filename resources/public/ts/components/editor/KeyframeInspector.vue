<template>
  <aside class="flex w-[22rem] shrink-0 flex-col gap-6 overflow-y-auto border-l border-border p-6">
    <div class="space-y-1">
      <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {{ $t('Keyframe :n', { n: String(index + 1) }) }}
      </p>
      <h2 class="text-xl font-semibold">{{ keyframe.title }}</h2>
      <ul v-if="keyframe.elements.length > 0" class="flex flex-wrap gap-1.5 pt-2" :aria-label="$t('Cast & sets')">
        <li
          v-for="name in keyframe.elements"
          :key="name"
          class="rounded-full border border-signal/40 bg-signal-soft/40 px-2.5 py-0.5 text-xs"
        >
          {{ name }}
        </li>
      </ul>
    </div>

    <KeyframeIssues v-if="issues && !isBusy(keyframe.status)" :issues="issues" />

    <p v-if="keyframe.renderError" class="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm">
      {{ keyframe.renderError }}
    </p>

    <div v-if="keyframe.renders.length > 1" class="space-y-2">
      <p class="text-sm text-muted-foreground">{{ $t('Versions') }}</p>
      <ul class="flex flex-wrap gap-2">
        <li v-for="(render, i) in keyframe.renders" :key="render.id">
          <button
            type="button"
            :class="
              cn(
                'relative block h-14 w-20 overflow-hidden rounded-md border border-border transition-colors hover:border-muted-foreground/60',
                render.chosen && 'border-signal ring-2 ring-signal/40',
              )
            "
            :disabled="render.chosen || isBusy(keyframe.status) || pick.processing"
            :title="$t('Version :n', { n: String(i + 1) })"
            @click="chooseRender(render.id)"
          >
            <img
              :src="render.thumbnailUrl"
              :alt="$t('Version :n', { n: String(i + 1) })"
              class="size-full object-cover"
            />
            <span
              class="absolute right-1 bottom-1 rounded bg-background/80 px-1 text-[10px] font-semibold tabular-nums"
            >
              {{ i + 1 }}
            </span>
          </button>
        </li>
      </ul>
    </div>

    <p v-if="checkIssues.length > 0" class="rounded-lg border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm">
      <span class="font-medium">{{ $t('The check found') }}</span>
      <span class="text-muted-foreground"> · {{ checkIssues.join(' ') }}</span>
    </p>

    <section
      v-if="adjustment || sent"
      class="space-y-3 rounded-lg border border-border bg-muted/30 p-4"
      aria-labelledby="adjustment-heading"
    >
      <p id="adjustment-heading" class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {{ $t('How this version was made') }}
      </p>
      <div v-if="adjustment" class="space-y-1">
        <p class="text-xs font-medium text-muted-foreground">
          {{ adjustment.fromCheck ? $t('From the check') : $t('You asked') }}
        </p>
        <p class="text-sm leading-relaxed">{{ adjustment.request }}</p>
      </div>
      <div v-if="adjustment && adjustment.instruction !== adjustment.request" class="space-y-1">
        <p class="text-xs font-medium text-muted-foreground">{{ $t('Made precise by the director') }}</p>
        <p class="text-sm leading-relaxed whitespace-pre-line">{{ adjustment.instruction }}</p>
      </div>
      <p v-if="stillness !== null" class="text-sm">
        {{ $t('Background kept: :percent%', { percent: String(Math.round(stillness * 100)) }) }}
      </p>
      <div v-if="sent" class="space-y-1">
        <p class="text-xs font-medium text-muted-foreground">{{ $t('Sent to :model', { model: sent.model }) }}</p>
        <ol class="list-inside list-decimal text-sm leading-relaxed">
          <li v-for="(image, i) in sent.images" :key="i">{{ image }}</li>
        </ol>
        <p v-if="sent.images.length === 0" class="text-sm text-muted-foreground">
          {{ $t('No images, only the text.') }}
        </p>
        <details class="pt-1">
          <summary class="cursor-pointer text-sm text-signal">{{ $t('Show the full prompt') }}</summary>
          <p class="mt-2 text-xs leading-relaxed whitespace-pre-line text-muted-foreground">{{ sent.prompt }}</p>
        </details>
      </div>
    </section>

    <div v-if="keyframe.destroyUrl || keyframe.copyUrl" class="mt-auto space-y-2 border-t border-border pt-6">
      <Button
        v-if="keyframe.copyUrl"
        type="button"
        variant="outline"
        class="w-full"
        :disabled="!canCopy || copying.processing"
        @click="copy"
      >
        <Copy class="size-4" />
        {{ $t('Copy keyframe') }}
      </Button>
      <ConfirmDelete
        v-if="keyframe.destroyUrl"
        :action="keyframe.destroyUrl"
        :title="$t('Delete keyframe :n?', { n: String(index + 1) })"
        :description="$t('The keyframe and all its versions are removed. The keyframes after it move up.')"
      >
        <template #trigger>
          <Button
            type="button"
            variant="outline"
            class="w-full text-destructive hover:text-destructive"
            :disabled="!canDelete"
          >
            <Trash2 class="size-4" />
            {{ $t('Delete keyframe') }}
          </Button>
        </template>
      </ConfirmDelete>
      <p v-if="!canDelete" class="mt-2 text-sm text-muted-foreground">
        {{ $t('A keyframe can be deleted when all keyframes are drawn and the shot has more than one.') }}
      </p>
    </div>
  </aside>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import ConfirmDelete from '@public:components/ConfirmDelete.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Copy, Trash2 } from 'lucide-vue-next'
import { computed } from 'vue'

import KeyframeIssues from './KeyframeIssues.vue'
import type { PanelIssueGroup, PanelKeyframe } from './KeyframesPanel.vue'
import { isBusy } from './keyframeStatus'

const props = defineProps<{
  keyframe: PanelKeyframe
  index: number
  canDelete: boolean
  /** The shot has room for one more keyframe. */
  canCopy: boolean
  /** What the checks found wrong with this keyframe, with how to fix or dismiss it. */
  issues?: PanelIssueGroup | null
  /** Moving a person on the image; its controls show here while it is active. */
}>()

/** The request and rewritten instruction behind the chosen version, when it came from an adjustment. */
const adjustment = computed(() => {
  const chosen = props.keyframe.renders.find((render) => render.chosen)

  return chosen?.request && chosen.instruction
    ? { request: chosen.request, instruction: chosen.instruction, fromCheck: Boolean(chosen.requestFromCheck) }
    : null
})

/** What the automatic check found wrong in the attempt before the chosen version, if it redrew. */

const checkIssues = computed(() => props.keyframe.renders.find((render) => render.chosen)?.checkIssues ?? [])

/** What the image model got for the chosen version. */
const sent = computed(() => props.keyframe.renders.find((render) => render.chosen)?.sent ?? null)

/** How much of the background stayed in place compared with the image it was drawn on. */
const stillness = computed(() => props.keyframe.renders.find((render) => render.chosen)?.stillness ?? null)

const copying = useForm({})
const copy = () => props.keyframe.copyUrl && copying.post(props.keyframe.copyUrl, { preserveScroll: true })
const pick = useForm({ render: 0 })
const chooseRender = (id: number) => {
  if (!props.keyframe.chooseRenderUrl) return

  pick.transform(() => ({ render: id })).post(props.keyframe.chooseRenderUrl, { preserveScroll: true })
}
</script>
