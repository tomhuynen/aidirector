<template>
  <div class="flex min-h-0 flex-1 flex-col gap-4">
    <!-- One row: as many options fit as the first batch, further ones scroll sideways. -->
    <!-- One row at full height; options beyond the width scroll sideways. -->
    <div ref="row" class="flex min-h-0 flex-1 gap-2 overflow-x-auto pb-2">
      <div v-for="(option, i) in options" :key="option.id" class="relative flex h-full min-h-0 shrink-0">
        <!-- Clicking a picture chooses it; the chat can choose by number too. -->
        <button
          type="button"
          class="group flex h-full min-h-0 shrink-0 cursor-pointer items-center justify-center disabled:cursor-default"
          :disabled="pending || adjusting || choice.processing"
          :aria-label="
            field === 'plate' ? $t('Use place :n', { n: String(i + 1) }) : $t('Use option :n', { n: String(i + 1) })
          "
          @click="choose(option.id)"
        >
          <!-- Sized like the placeholders: one side fills the cell and the ratio sets the other, so the frame hugs the image. -->
          <span class="relative inline-flex max-h-full max-w-full" :style="tileSize">
            <img
              :src="option.imageUrl"
              :alt="field === 'plate' ? $t('Place :n', { n: String(i + 1) }) : $t('Option :n', { n: String(i + 1) })"
              :class="
                cn(
                  'size-full rounded-xl border border-border bg-card object-cover transition',
                  option.id === chosenId
                    ? 'border-signal ring-4 ring-signal/40'
                    : 'group-enabled:group-hover:border-signal group-enabled:group-hover:ring-4 group-enabled:group-hover:ring-signal/30',
                )
              "
            />
            <span
              class="absolute top-3 left-3 flex size-8 items-center justify-center rounded-full bg-background/80 text-sm font-semibold tabular-nums backdrop-blur-sm"
            >
              <LoaderCircle v-if="option.id === chosenId" class="size-4 animate-spin" />
              <template v-else>{{ i + 1 }}</template>
            </span>
          </span>
        </button>
      </div>

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

    <InputError :message="choice.errors.render ?? choice.errors.plate" />
  </div>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { cn } from '@shared/lib/utils'
import { LoaderCircle } from 'lucide-vue-next'
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue'

import Placeholder from './Placeholder.vue'

const props = defineProps<{
  options: { id: number; imageUrl: string }[]
  optionCount: number
  aspectRatio: string
  pending: boolean
  chooseUrl: string
  /** An adjusted option is being drawn. */
  adjusting?: boolean
  /** What the choice is sent as: an option of keyframe 1, or an empty place. */
  field?: 'render' | 'plate'
}>()

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

/** The option clicked, shown with a loader while it is sent. */
const chosenId = ref<number | null>(null)

const choose = (id: number) => {
  chosenId.value = id

  choice
    .transform(() => ({ [props.field ?? 'render']: id }))
    .post(props.chooseUrl, { preserveScroll: true, onFinish: () => (chosenId.value = null) })
}

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

// When an adjusted option arrives, scroll it into view, so it can be compared straight away.
watch(
  () => props.options.length,
  async (length, previous) => {
    if (previous !== undefined && length > previous) await scrollToEnd()
  },
)
</script>
