<template>
  <div class="flex shrink-0 items-center justify-between gap-3">
    <div class="flex items-center gap-2">
      <Button type="button" variant="outline" :disabled="disabled || more.processing" @click="askMore">
        <RefreshCw class="size-4" />
        {{ resetUrl ? $t('Draw again') : $t('More options') }}
      </Button>
      <Button
        v-if="resetUrl"
        type="button"
        variant="ghost"
        :disabled="disabled || reset.processing"
        @click="reset.post(resetUrl, { preserveScroll: true })"
      >
        <ArrowLeft class="size-4" />
        {{ $t('Choose another place') }}
      </Button>
    </div>
    <div class="flex items-center gap-3">
      <InputError :message="choice.errors.render ?? choice.errors.plate ?? reset.errors.plate" />
      <Button type="button" :disabled="disabled || selected === null || choice.processing" @click="choose">
        {{ field === 'plate' ? $t('Use this place') : $t('Use this keyframe') }}
        <ArrowRight class="size-4" />
      </Button>
    </div>
  </div>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { Button } from '@shared:ui/button'
import { ArrowLeft, ArrowRight, RefreshCw } from 'lucide-vue-next'

/** The choice under the options of keyframe 1 or the places: draw more, go back to the places, or use the selected one. */
const props = defineProps<{
  /** The id of the selected option or place. */
  selected: number | null
  chooseUrl: string
  moreUrl: string
  /** What the choice is sent as: an option of keyframe 1, or an empty place. */
  field?: 'render' | 'plate'
  /** Keyframe 1 is drawn on a chosen place; this goes back to the places. */
  resetUrl?: string | null
  disabled?: boolean
}>()

const choice = useForm<{ render?: number | null; plate?: number | null }>({})
const more = useForm({})
const reset = useForm<{ plate?: string }>({})

const choose = () => {
  if (props.selected === null) return

  choice
    .transform(() => ({ [props.field ?? 'render']: props.selected }))
    .post(props.chooseUrl, { preserveScroll: true })
}

const askMore = () => more.post(props.moreUrl, { preserveScroll: true })
</script>
