<template>
  <aside class="flex w-[22rem] shrink-0 flex-col gap-6 overflow-y-auto border-l border-border p-6">
    <div class="space-y-1">
      <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ $t('Keyframe 1') }}</p>
      <h2 v-if="keyframe" class="text-xl font-semibold">{{ keyframe.title }}</h2>
      <div v-else class="h-7 w-40 animate-pulse rounded bg-secondary" />
    </div>

    <div class="space-y-3 rounded-xl border border-signal/40 bg-signal-soft/30 px-4 py-4 text-sm leading-relaxed">
      <p class="font-semibold">{{ $t('Choose the first keyframe') }}</p>
      <p class="text-muted-foreground">
        {{
          $t(
            'The first keyframe sets the character, the place and the look for the whole shot. Pick the option you like best; the other keyframes are then drawn to match it.',
          )
        }}
      </p>
    </div>

    <form v-if="adjustUrl" class="space-y-3" @submit.prevent="adjust">
      <div class="space-y-1.5">
        <Label for="option-adjust">{{ $t('Adjust the selected option') }}</Label>
        <Textarea
          id="option-adjust"
          v-model="adjustment.instruction"
          rows="3"
          maxlength="500"
          :disabled="busy || selected === null"
          :placeholder="
            selected === null
              ? $t('Select an option first')
              : $t('For example: the line runs across the quay in front of her')
          "
          class="text-[15px] leading-relaxed"
        />
        <InputError :message="adjustment.errors.instruction ?? adjustment.errors.render" />
        <p class="text-sm text-muted-foreground">{{ $t('The adjusted version is added as a new option.') }}</p>
      </div>
      <Button
        type="submit"
        class="w-full"
        :disabled="busy || selected === null || adjustment.instruction.trim() === '' || adjustment.processing"
      >
        <LoaderCircle v-if="busy" class="size-4 animate-spin" />
        <Wand2 v-else class="size-4" />
        {{ busy ? $t('Adjusting…') : $t('Apply change') }}
      </Button>
    </form>

    <div class="space-y-1.5">
      <p class="text-sm text-muted-foreground">{{ $t('Description') }}</p>
      <p v-if="keyframe" class="rounded-lg border border-border bg-card px-3.5 py-2.5 text-[15px] leading-relaxed">
        {{ keyframe.description }}
      </p>
      <div v-else class="space-y-2 rounded-lg border border-border bg-card px-3.5 py-3">
        <div class="h-3.5 w-full animate-pulse rounded bg-secondary" />
        <div class="h-3.5 w-11/12 animate-pulse rounded bg-secondary" />
        <div class="h-3.5 w-3/5 animate-pulse rounded bg-secondary" />
      </div>
    </div>
  </aside>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { Button } from '@shared:ui/button'
import { Label } from '@shared:ui/label'
import { Textarea } from '@shared:ui/textarea'
import { LoaderCircle, Wand2 } from 'lucide-vue-next'

/**
 * Without a keyframe the plan is still being made: the same column shows
 * with placeholders, so the layout does not jump when the plan arrives.
 */
const props = defineProps<{
  keyframe?: { title: string; description: string }
  /** Adjusts the selected option; the result is added as a new option. */
  adjustUrl?: string
  selected?: number | null
  /** Options or an adjustment are being drawn. */
  busy?: boolean
}>()

const adjustment = useForm<{ instruction: string; render?: number }>({ instruction: '' })

const adjust = () => {
  if (!props.adjustUrl || props.selected == null) return

  adjustment
    .transform((data) => ({ ...data, render: props.selected ?? undefined }))
    .post(props.adjustUrl, { preserveScroll: true, onSuccess: () => adjustment.reset() })
}
</script>
