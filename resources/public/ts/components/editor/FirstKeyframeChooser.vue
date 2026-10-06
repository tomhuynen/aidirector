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

    <div class="flex shrink-0 items-center justify-between gap-3">
      <div class="flex items-center gap-2">
        <Button type="button" variant="outline" :disabled="pending || adjusting || more.processing" @click="askMore">
          <RefreshCw class="size-4" />
          {{ resetUrl ? $t('Draw again') : $t('More options') }}
        </Button>
        <Button
          v-if="resetUrl"
          type="button"
          variant="ghost"
          :disabled="pending || adjusting || reset.processing"
          @click="reset.post(resetUrl, { preserveScroll: true })"
        >
          <ArrowLeft class="size-4" />
          {{ $t('Choose another place') }}
        </Button>
      </div>
      <div class="flex items-center gap-3">
        <InputError :message="choice.errors.render ?? choice.errors.plate ?? reset.errors.plate" />
        <Button
          type="button"
          :disabled="pending || adjusting || selectedId === null || choice.processing"
          @click="choose"
        >
          {{ field === 'plate' ? $t('Use this place') : $t('Use this keyframe') }}
          <ArrowRight class="size-4" />
        </Button>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { ArrowLeft, ArrowRight, Check, LoaderCircle, RefreshCw } from 'lucide-vue-next'
import { computed, nextTick, useTemplateRef, watch } from 'vue'

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

const choice = useForm<{ render?: number | null; plate?: number | null }>({})
const more = useForm({})
const reset = useForm<{ plate?: string }>({})

const choose = () => {
  if (selectedId.value === null) return

  choice
    .transform(() => ({ [props.field ?? 'render']: selectedId.value }))
    .post(props.chooseUrl, { preserveScroll: true })
}

const askMore = () => more.post(props.moreUrl, { preserveScroll: true })

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
