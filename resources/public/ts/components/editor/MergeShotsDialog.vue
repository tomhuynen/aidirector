<template>
  <Dialog v-model:open="open">
    <DialogContent>
      <form class="space-y-6" @submit.prevent="submit">
        <DialogHeader class="space-y-3">
          <DialogTitle class="font-display text-2xl font-medium">
            {{ $t('Merge :count shots', { count: String(shots.length) }) }}
          </DialogTitle>
          <DialogDescription>
            {{
              $t(
                'Their videos are joined into one shot of :seconds s. The shots themselves are kept, so you can unmerge later.',
                { seconds: String(totalSeconds) },
              )
            }}
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-2">
          <Label for="merge-title">{{ $t('Title') }}</Label>
          <Input id="merge-title" v-model="form.title" :maxlength="120" required />
          <InputError :message="form.errors.title" />
        </div>

        <div class="space-y-2">
          <p class="text-sm font-medium">{{ $t('Between the clips') }}</p>
          <TransitionPicker v-model="form.transition" :transitions="transitions" />
        </div>

        <InputError :message="form.errors.shots" />

        <DialogFooter class="gap-2">
          <DialogClose as-child>
            <Button type="button" variant="secondary">{{ $t('Cancel') }}</Button>
          </DialogClose>
          <Button type="submit" :disabled="form.processing">
            <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
            {{ $t('Merge') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { Button } from '@shared:ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@shared:ui/dialog'
import { Input } from '@shared:ui/input'
import { Label } from '@shared:ui/label'
import { LoaderCircle } from 'lucide-vue-next'
import { computed, watch } from 'vue'

import TransitionPicker, { type ShotTransitionOption } from './TransitionPicker.vue'

const props = defineProps<{
  shots: { id: string; title: string; duration: number }[]
  transitions: ShotTransitionOption[]
  mergeUrl: string
}>()

const emit = defineEmits<{ merged: [] }>()

const open = defineModel<boolean>('open', { required: true })

const form = useForm({
  shots: [] as string[],
  title: '',
  transition: 'crossfade',
})

const totalSeconds = computed(() => props.shots.reduce((sum, shot) => sum + shot.duration, 0))

watch(open, (isOpen) => {
  if (isOpen) {
    form.clearErrors()
    form.shots = props.shots.map((shot) => shot.id)
    form.title = props.shots[0]?.title ?? ''
  }
})

const submit = () => {
  form.post(props.mergeUrl, {
    onSuccess: () => {
      open.value = false
      emit('merged')
    },
  })
}
</script>
