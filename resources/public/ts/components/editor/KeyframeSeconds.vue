<template>
  <div class="space-y-0.5 px-1 text-sm text-muted-foreground">
    <p v-if="!url" class="tabular-nums">{{ seconds.toFixed(1) }} s</p>
    <div v-else class="-ml-1.5 flex items-center">
      <Button
        type="button"
        variant="ghost"
        size="icon"
        class="relative size-6 after:absolute after:-inset-x-3 after:-top-2 after:-bottom-3"
        :aria-label="$t('Shorter')"
        :disabled="disabled || draft <= STEP"
        @click="change(-STEP)"
      >
        <Minus class="size-3" />
      </Button>
      <span class="w-11 text-center tabular-nums" :title="$t('How long this keyframe lasts')">
        {{ draft.toFixed(1) }} s
      </span>
      <Button
        type="button"
        variant="ghost"
        size="icon"
        class="relative size-6 after:absolute after:-inset-x-3 after:-top-2 after:-bottom-3"
        :aria-label="$t('Longer')"
        :disabled="disabled"
        @click="change(STEP)"
      >
        <Plus class="size-3" />
      </Button>
    </div>
    <InputError :message="form.errors.seconds" />
  </div>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { Button } from '@shared:ui/button'
import { Minus, Plus } from 'lucide-vue-next'
import { ref, watch } from 'vue'

/**
 * How long a keyframe lasts, under its thumbnail. The length changes half
 * a second a click and is saved once the clicks stop, so a few clicks make
 * one change.
 */
const props = defineProps<{
  seconds: number
  url?: string | null
  disabled: boolean
}>()

const STEP = 0.5

const draft = ref(props.seconds)

watch(
  () => props.seconds,
  (seconds) => (draft.value = seconds),
)

const form = useForm<{ seconds: number }>({ seconds: props.seconds })

let timer: ReturnType<typeof setTimeout> | undefined

const change = (by: number) => {
  draft.value = Math.max(STEP, Math.round((draft.value + by) / STEP) * STEP)
  clearTimeout(timer)
  timer = setTimeout(save, 700)
}

const save = () => {
  if (!props.url || draft.value === props.seconds) return

  form.seconds = draft.value
  form.post(props.url, { preserveScroll: true, onError: () => (draft.value = props.seconds) })
}
</script>
