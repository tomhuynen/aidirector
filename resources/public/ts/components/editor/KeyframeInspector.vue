<template>
  <aside class="flex w-[22rem] shrink-0 flex-col gap-6 overflow-y-auto border-l border-border p-6">
    <div class="space-y-1">
      <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {{ $t('Keyframe :n', { n: String(index + 1) }) }}
      </p>
      <h2 class="text-xl font-semibold">{{ keyframe.title }}</h2>
    </div>

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
        <p v-if="!hasRender && !keyframe.rendering" class="text-sm text-muted-foreground">
          {{ $t('Adjustments are possible once the image is rendered.') }}
        </p>
      </div>
      <Button type="submit" class="w-full" :disabled="!canTweak || tweak.instruction.trim() === '' || tweak.processing">
        <LoaderCircle v-if="keyframe.rendering" class="size-4 animate-spin" />
        <Wand2 v-else class="size-4" />
        {{ keyframe.rendering ? $t('Generating image…') : $t('Apply change') }}
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

    <section
      v-if="adjustment"
      class="space-y-3 rounded-lg border border-border bg-muted/30 p-4"
      aria-labelledby="adjustment-heading"
    >
      <p id="adjustment-heading" class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {{ $t('How this version was made') }}
      </p>
      <div class="space-y-1">
        <p class="text-xs font-medium text-muted-foreground">{{ $t('You asked') }}</p>
        <p class="text-sm leading-relaxed">{{ adjustment.request }}</p>
      </div>
      <div class="space-y-1">
        <p class="text-xs font-medium text-muted-foreground">{{ $t('Instruction sent to the image model') }}</p>
        <p class="text-sm leading-relaxed whitespace-pre-line">{{ adjustment.instruction }}</p>
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
  </aside>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Label } from '@shared:ui/label'
import { Textarea } from '@shared:ui/textarea'
import { LoaderCircle, RefreshCw, Wand2 } from 'lucide-vue-next'
import { computed } from 'vue'

import type { PanelKeyframe } from './KeyframesPanel.vue'

const props = defineProps<{
  keyframe: PanelKeyframe
  index: number
}>()

const hasRender = computed(() => props.keyframe.renders.length > 0)

/** The request and rewritten instruction behind the chosen version, when it came from an adjustment. */
const adjustment = computed(() => {
  const chosen = props.keyframe.renders.find((render) => render.chosen)

  return chosen?.request && chosen.instruction ? { request: chosen.request, instruction: chosen.instruction } : null
})
const canTweak = computed(() => hasRender.value && Boolean(props.keyframe.tweakUrl) && !props.keyframe.rendering)

const tweak = useForm({ instruction: '' })
const pick = useForm({ render: 0 })
const description = useForm({ description: props.keyframe.description })

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
