<template>
  <form class="flex min-h-0 flex-1 flex-col overflow-y-auto p-8" @submit.prevent="submit">
    <header class="space-y-2">
      <h2 class="text-3xl font-semibold">{{ $t('Describe your shot') }}</h2>
      <p class="text-lg text-muted-foreground">{{ $t('Tell us what should happen in this shot. Keep it simple.') }}</p>
    </header>

    <div class="mt-8 grid gap-x-6 gap-y-6 lg:grid-cols-[1fr_16rem]">
      <BriefField
        v-model="form.title"
        :label="$t('Title')"
        :max="120"
        required
        autofocus
        :placeholder="$t('No smoking')"
        :error="form.errors.title"
      />
      <div class="space-y-2">
        <label :for="durationId" class="block text-[15px] font-medium">
          {{ $t('Duration') }}
          <span class="font-normal text-muted-foreground">({{ $t('seconds') }})</span>
        </label>
        <input
          :id="durationId"
          v-model="form.duration"
          type="number"
          min="2"
          max="30"
          :placeholder="String(project.defaultDuration)"
          class="block w-full rounded-lg border border-input bg-background px-4 py-3 text-[15px] placeholder:text-muted-foreground/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        />
        <InputError :message="form.errors.duration" />
      </div>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
      <BriefField
        v-model="form.subject"
        :label="$t('Subject')"
        :max="1000"
        rows="4"
        required
        :placeholder="$t('Who or what do we see?')"
        :error="form.errors.subject"
      />
      <BriefField
        v-model="form.action"
        :label="$t('Action')"
        :max="1000"
        rows="4"
        required
        :placeholder="$t('He lights a cigarette, notices the no smoking sign, puts it out and gives a thumbs up.')"
        :error="form.errors.action"
      />
      <BriefField
        v-model="form.takeaway"
        :label="$t('Takeaway')"
        :max="1000"
        rows="3"
        required
        :placeholder="$t('Smoking is prohibited in this area.')"
        :error="form.errors.takeaway"
      />
      <BriefField
        v-model="form.notes"
        :label="$t('Notes')"
        :hint="$t('optional')"
        :max="2000"
        rows="3"
        :placeholder="$t('Keep the style consistent with previous shots. Use the same character.')"
        :error="form.errors.notes"
      />
    </div>

    <footer class="mt-auto flex items-center justify-end gap-3 pt-8">
      <Button v-if="cancelUrl" as-child variant="ghost">
        <Link :href="cancelUrl">{{ $t('Cancel') }}</Link>
      </Button>
      <Button type="submit" size="lg" :disabled="form.processing">
        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
        {{ $t('Continue') }}
        <ArrowRight class="size-4" />
      </Button>
    </footer>
  </form>
</template>
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import InputError from '@public:components/Form/InputError.vue'
import { store } from '@routes/public/shots'
import { Button } from '@shared:ui/button'
import { ArrowRight, LoaderCircle } from 'lucide-vue-next'
import { useId } from 'vue'

import BriefField from './BriefField.vue'

type ShotForm = Omit<Inertia.Requests.Shots.Store, 'notes' | 'purposeOverride' | 'aspectRatioOverride' | 'duration'> & {
  notes: string
  duration: number | string | null
}

const props = defineProps<{
  project: Inertia.Pages.Shots.Update['project']
  shot: Inertia.Pages.Shots.Update['shot']
  cancelUrl?: string
}>()

const durationId = useId()
const isNew = !props.shot.id

// Debug defaults for a new shot: the placeholders as real values, so the
// storyline flow can be tried with one click. Remove before release.
const debugDefaults = {
  title: 'No smoking',
  subject: 'A man in a suit in an industrial area next to a no smoking sign.',
  action: 'He lights a cigarette, notices the no smoking sign, puts it out and gives a thumbs up.',
  takeaway: 'Smoking is prohibited in this area.',
  notes: 'Keep the style consistent with previous shots. Use the same character.',
}

const form = useForm<ShotForm>({
  title: isNew ? debugDefaults.title : props.shot.title,
  subject: isNew ? debugDefaults.subject : props.shot.subject,
  action: isNew ? debugDefaults.action : props.shot.action,
  takeaway: isNew ? debugDefaults.takeaway : props.shot.takeaway,
  notes: isNew ? debugDefaults.notes : (props.shot.notes ?? ''),
  duration: props.shot.duration ?? null,
})

const submit = () => {
  form
    .transform((data) => ({ ...data, duration: data.duration === '' ? null : data.duration }))
    .post(props.shot.links?.update ?? store.url({ project: props.project.id }))
}
</script>
