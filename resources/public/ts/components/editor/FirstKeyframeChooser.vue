<template>
  <div class="flex min-h-0 flex-1 flex-col gap-4">
    <!-- One row: as many options fit as the first batch, further ones scroll sideways. -->
    <!-- One row at full height; options beyond the width scroll sideways. -->
    <div ref="row" class="flex min-h-0 flex-1 gap-2 overflow-x-auto pb-2">
      <button
        v-for="(option, i) in options"
        :key="option.id"
        type="button"
        class="group flex h-full min-h-0 shrink-0 items-center justify-center"
        :aria-pressed="option.id === selectedId"
        :disabled="pending || adjusting"
        @click="selectedId = option.id"
      >
        <!-- Sized like the placeholders: one side fills the cell and the ratio sets the other, so the frame hugs the image. -->
        <span class="relative inline-flex max-h-full max-w-full" :style="tileSize">
          <img
            :src="option.imageUrl"
            :alt="field === 'plate' ? $t('Place :n', { n: String(i + 1) }) : $t('Option :n', { n: String(i + 1) })"
            :class="
              cn(
                'size-full rounded-xl border border-border bg-card object-cover transition',
                option.id === selectedId
                  ? 'border-signal ring-4 ring-signal/40'
                  : 'group-hover:border-muted-foreground/60',
              )
            "
          />
          <span
            :class="
              cn(
                'absolute top-3 left-3 flex size-8 items-center justify-center rounded-full bg-background/80 text-sm font-semibold tabular-nums backdrop-blur-sm',
                option.id === selectedId && 'bg-signal text-primary-foreground',
              )
            "
          >
            <Check v-if="option.id === selectedId" class="size-4" />
            <template v-else>{{ i + 1 }}</template>
          </span>
        </span>
      </button>

      <div
        v-for="n in placeholders"
        :key="`pending-${n}`"
        class="flex h-full min-h-0 shrink-0 items-center justify-center"
      >
        <Placeholder class="max-h-full max-w-full rounded-xl bg-card" :style="tileSize">
          <LoaderCircle class="size-6 animate-spin text-signal" />
        </Placeholder>
      </div>
    </div>

    <FirstKeyframeActions
      :selected="selectedId"
      :choose-url="chooseUrl"
      :more-url="moreUrl"
      :field="field"
      :reset-url="resetUrl"
      :disabled="pending || adjusting"
    />
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { Check, LoaderCircle } from 'lucide-vue-next'
import { computed, nextTick, useTemplateRef, watch } from 'vue'

import FirstKeyframeActions from './FirstKeyframeActions.vue'
import Placeholder from './Placeholder.vue'

const props = defineProps<{
  options: { id: number; imageUrl: string }[]
  optionCount: number
  aspectRatio: string
  pending: boolean
  chooseUrl: string
  moreUrl: string
  /** An adjusted option is being drawn. */
  adjusting?: boolean
  /** What the choice is sent as: an option of keyframe 1, or an empty place. */
  field?: 'render' | 'plate'
  /** Keyframe 1 is drawn on a chosen place; this goes back to the places. */
  resetUrl?: string | null
}>()

/** The option the director selected; shared with the column on the right, which adjusts it. */
const selectedId = defineModel<number | null>('selected', { default: null })

/**
 * While options are being drawn, the batch that is still missing shows as spinners.
 */
const placeholders = computed(() => {
  if (props.adjusting && !props.pending) return 1
  if (!props.pending) return 0

  const drawn = props.options.length % props.optionCount

  return props.optionCount - drawn
})

// Always the full height of the row; the ratio sets the width, and the row scrolls when they do not fit.
const tileSize = computed(() => ({
  aspectRatio: props.aspectRatio.replace(':', ' / '),
  height: '100%',
}))

const row = useTemplateRef<HTMLElement>('row')

const scrollToEnd = async () => {
  await nextTick()
  row.value?.scrollTo({ left: row.value.scrollWidth, behavior: 'smooth' })
}

// The tile of an option being adjusted is added at the end; bring it into view.
watch(
  () => props.adjusting,
  (adjusting) => {
    if (adjusting) void scrollToEnd()
  },
)

// A single option, such as keyframe 1 drawn on the chosen place, is selected straight away so it can be adjusted or used.
watch(
  () => props.options.map((option) => option.id),
  (ids) => {
    if (ids.length === 1 && (selectedId.value === null || !ids.includes(selectedId.value))) selectedId.value = ids[0]
  },
  { immediate: true },
)

// When an adjusted option arrives, select it and scroll it into view, so it can be compared and used straight away.
watch(
  () => props.options.length,
  async (length, previous) => {
    if (previous === undefined || length <= previous) return

    if (length === previous + 1 && !props.pending) {
      selectedId.value = props.options[length - 1]?.id ?? selectedId.value
    }

    await scrollToEnd()
  },
)
</script>
