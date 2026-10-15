<template>
  <aside class="flex w-[22rem] shrink-0 flex-col gap-6 overflow-y-auto border-l border-border p-6">
    <div class="space-y-1">
      <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
        {{ $t('Keyframe :n', { n: String(position) }) }}
      </p>
      <h2 class="text-xl font-semibold">{{ $t('New keyframe') }}</h2>
    </div>

    <form class="space-y-4" @submit.prevent="submit">
      <div class="space-y-1.5">
        <Label for="new-keyframe-title">{{ $t('Title') }}</Label>
        <Input
          id="new-keyframe-title"
          v-model="form.title"
          maxlength="60"
          :placeholder="$t('For example: Walks away')"
          class="text-[15px]"
        />
        <InputError :message="form.errors.title" />
      </div>

      <div class="space-y-1.5">
        <Label for="new-keyframe-summary">{{ $t('What happens') }}</Label>
        <Textarea
          id="new-keyframe-summary"
          v-model="form.summary"
          rows="3"
          maxlength="300"
          :placeholder="$t('For example: The guard hands the permit back')"
          class="text-[15px] leading-relaxed"
        />
        <InputError :message="form.errors.summary" />
        <p class="text-sm text-muted-foreground">
          {{ $t('It is added at the end and drawn to match the first keyframe.') }}
        </p>
      </div>

      <Button
        type="submit"
        class="w-full"
        :disabled="form.processing || form.title.trim() === '' || form.summary.trim() === ''"
      >
        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
        <Plus v-else class="size-4" />
        {{ $t('Add keyframe') }}
      </Button>
    </form>
  </aside>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { Button } from '@shared:ui/button'
import { Input } from '@shared:ui/input'
import { Label } from '@shared:ui/label'
import { Textarea } from '@shared:ui/textarea'
import { LoaderCircle, Plus } from 'lucide-vue-next'

const props = defineProps<{
  position: number
  storeUrl: string
}>()

const emit = defineEmits<{ added: [] }>()

const form = useForm({ title: '', summary: '' })

const submit = () =>
  form.post(props.storeUrl, {
    preserveScroll: true,
    onSuccess: () => {
      form.reset()
      emit('added')
    },
  })
</script>
