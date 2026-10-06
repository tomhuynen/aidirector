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

    <section
      v-if="issues && !keyframe.rendering"
      class="space-y-3 rounded-lg border border-amber-500/50 bg-amber-500/10 p-4 text-sm"
      aria-labelledby="keyframe-issues-heading"
    >
      <p id="keyframe-issues-heading" class="font-medium">{{ $t('The check found something') }}</p>
      <ul class="space-y-2">
        <li v-for="(issue, i) in issues.issues" :key="i" class="flex gap-2 leading-relaxed">
          <TriangleAlert class="mt-0.5 size-4 shrink-0 text-amber-500" />
          {{ issue }}
        </li>
      </ul>
      <div class="flex gap-2 pl-6">
        <Button
          v-if="issues.fixUrl"
          type="button"
          size="sm"
          :disabled="resolving.processing"
          @click="resolve(issues.fixUrl)"
        >
          <Wand2 class="size-4" />
          {{ $t('Fix') }}
        </Button>
        <Button
          type="button"
          size="sm"
          variant="ghost"
          :disabled="resolving.processing"
          @click="resolve(issues.dismissUrl)"
        >
          {{ $t('Dismiss') }}
        </Button>
      </div>
    </section>

    <p v-if="keyframe.renderError" class="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm">
      {{ keyframe.renderError }}
    </p>

    <form class="space-y-3" @submit.prevent="applyTweak">
      <div class="space-y-1.5">
        <Label for="keyframe-tweak">{{ $t('Adjust this image') }}</Label>
        <Textarea
          id="keyframe-tweak"
          v-model="tweak.instruction"
          rows="3"
          maxlength="500"
          :disabled="!canTweak"
          :placeholder="$t('For example: remove the lighter from his hand')"
          class="text-[15px] leading-relaxed"
        />
        <InputError :message="tweak.errors.instruction" />
        <label class="flex items-start gap-2 text-sm text-muted-foreground">
          <Checkbox v-model="tweak.rewrite" :disabled="!canTweak" class="mt-0.5" />
          <span>{{ $t('Let the director make my request precise first') }}</span>
        </label>
        <p v-if="!hasRender && !keyframe.rendering" class="text-sm text-muted-foreground">
          {{ $t('Adjustments are possible once the image is rendered.') }}
        </p>
      </div>
      <Button type="submit" class="w-full" :disabled="!canTweak || tweak.instruction.trim() === '' || tweak.processing">
        <LoaderCircle v-if="keyframe.rendering" class="size-4 animate-spin" />
        <Wand2 v-else class="size-4" />
        {{ keyframe.rendering ? busyLabel : $t('Apply change') }}
      </Button>
    </form>

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
            :disabled="render.chosen || keyframe.rendering || pick.processing"
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

    <p v-if="checkWarning" class="rounded-lg border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm">
      <span class="font-medium">{{ $t('The check could not see this') }}</span>
      <span class="text-muted-foreground"> · {{ checkWarning }}</span>
    </p>

    <p v-if="checkIssues.length > 0" class="rounded-lg border border-amber-500/50 bg-amber-500/10 px-4 py-3 text-sm">
      <span class="font-medium">{{ $t('The check found') }}</span>
      <span class="text-muted-foreground"> · {{ checkIssues.join(' ') }}</span>
    </p>

    <p v-if="checkProblems.length > 0" class="rounded-lg border border-border bg-muted/30 px-4 py-3 text-sm">
      <span class="font-medium">{{ $t('Redrawn automatically') }}</span>
      <span class="text-muted-foreground"> · {{ checkProblems.join(' ') }}</span>
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

    <form class="space-y-3 border-t border-border pt-6" @submit.prevent="rewrite">
      <div class="space-y-1.5">
        <Label for="keyframe-description">{{ $t('Description') }}</Label>
        <Textarea
          id="keyframe-description"
          v-model="description.description"
          rows="6"
          maxlength="500"
          :disabled="!keyframe.updateUrl || keyframe.rendering"
          class="text-[15px] leading-relaxed"
        />
        <InputError :message="description.errors.description" />
        <p
          v-if="keyframe.needsDescription"
          class="rounded-lg border border-amber-500/50 bg-amber-500/10 px-3 py-2 text-sm"
        >
          {{
            $t(
              'This copy still has the description of the keyframe it came from. Describe what this step shows, so the check can judge it.',
            )
          }}
        </p>
        <p class="text-sm text-muted-foreground">
          {{ $t('Changing the description renders the keyframe again from scratch.') }}
        </p>
      </div>
      <Button
        type="submit"
        variant="outline"
        class="w-full"
        :disabled="!keyframe.updateUrl || keyframe.rendering || !descriptionChanged || description.processing"
      >
        <RefreshCw class="size-4" />
        {{ $t('Render from description') }}
      </Button>
    </form>

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
import InputError from '@public:components/Form/InputError.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Checkbox } from '@shared:ui/checkbox'
import { Label } from '@shared:ui/label'
import { Textarea } from '@shared:ui/textarea'
import { Copy, LoaderCircle, RefreshCw, Trash2, TriangleAlert, Wand2 } from 'lucide-vue-next'
import { computed, watch } from 'vue'

import type { PanelIssueGroup, PanelKeyframe } from './KeyframesPanel.vue'

const props = defineProps<{
  keyframe: PanelKeyframe
  index: number
  canDelete: boolean
  /** The shot has room for one more keyframe. */
  canCopy: boolean
  /** What the checks found wrong with this keyframe, with how to fix or dismiss it. */
  issues?: PanelIssueGroup | null
}>()

const hasRender = computed(() => props.keyframe.renders.length > 0)

/** The request and rewritten instruction behind the chosen version, when it came from an adjustment. */
const adjustment = computed(() => {
  const chosen = props.keyframe.renders.find((render) => render.chosen)

  return chosen?.request && chosen.instruction
    ? { request: chosen.request, instruction: chosen.instruction, fromCheck: Boolean(chosen.requestFromCheck) }
    : null
})
const busyLabel = computed(() => {
  if (props.keyframe.renderStage === 'checking') return $t('Checking the image…')
  if (props.keyframe.renderStage === 'fixing') return $t('Fixing a mistake the check found…')

  return $t('Generating image…')
})

/** What the automatic check found wrong in the attempt before the chosen version, if it redrew. */
const checkWarning = computed(() => props.keyframe.renders.find((render) => render.chosen)?.checkWarning ?? null)

const checkIssues = computed(() => props.keyframe.renders.find((render) => render.chosen)?.checkIssues ?? [])

/** What the image model got for the chosen version. */
const sent = computed(() => props.keyframe.renders.find((render) => render.chosen)?.sent ?? null)

/** How much of the background stayed in place compared with the image it was drawn on. */
const stillness = computed(() => props.keyframe.renders.find((render) => render.chosen)?.stillness ?? null)

const checkProblems = computed(() => props.keyframe.renders.find((render) => render.chosen)?.checkProblems ?? [])

const canTweak = computed(() => hasRender.value && Boolean(props.keyframe.tweakUrl) && !props.keyframe.rendering)

/** Fixing redraws the keyframe told what is wrong; dismissing only takes the issues off the list. */
const resolving = useForm({})
const resolve = (url: string) => resolving.post(url, { preserveScroll: true })

const tweak = useForm({ instruction: '', rewrite: false })
const copying = useForm({})
const copy = () => props.keyframe.copyUrl && copying.post(props.keyframe.copyUrl, { preserveScroll: true })
const pick = useForm({ render: 0 })
const description = useForm({ description: props.keyframe.description })

/*
 * An adjustment can rewrite the description on the server. Follow it while the
 * director has not edited the text, so a stale description is never sent back.
 */
watch(
  () => props.keyframe.description,
  (next, previous) => {
    if (description.description.trim() !== previous.trim()) return

    description.defaults({ description: next })
    description.reset()
  },
)

const descriptionChanged = computed(() => description.description.trim() !== props.keyframe.description.trim())

const applyTweak = () => {
  if (!props.keyframe.tweakUrl) return

  tweak.post(props.keyframe.tweakUrl, { preserveScroll: true, onSuccess: () => tweak.reset() })
}

const chooseRender = (id: number) => {
  if (!props.keyframe.chooseRenderUrl) return

  pick.transform(() => ({ render: id })).post(props.keyframe.chooseRenderUrl, { preserveScroll: true })
}

const rewrite = () => {
  if (!props.keyframe.updateUrl) return

  description.post(props.keyframe.updateUrl, { preserveScroll: true })
}
</script>
