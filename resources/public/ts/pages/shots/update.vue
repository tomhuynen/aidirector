<template>
  <Page
    :eyebrow="project.title"
    :title="shot.id ? $t('Edit shot') : $t('New shot')"
    :description="$t('Describe the intent, not the camera. The director proposes how to film it.')"
  >
    <form class="grid gap-12 lg:grid-cols-[1fr_320px]" @submit.prevent="submit">
      <div class="space-y-12">
        <section class="space-y-6">
          <SectionHeading :title="$t('Intent')" />
          <FormInput
            v-model="form.title"
            required
            autofocus
            :label="$t('Working title')"
            :placeholder="$t('Posting the letter')"
            :error="form.errors.title"
          />
          <FormTextArea
            v-model="form.subject"
            required
            rows="2"
            :label="$t('Subject')"
            :placeholder="$t('Who or what do we see?')"
            :error="form.errors.subject"
          />
          <FormTextArea
            v-model="form.action"
            required
            rows="3"
            :label="$t('Action')"
            :placeholder="$t('What happens, from start to end?')"
            :error="form.errors.action"
          />
          <FormTextArea
            v-model="form.takeaway"
            required
            rows="2"
            :label="$t('Takeaway')"
            :placeholder="$t('What should the viewer understand or feel afterwards?')"
            :error="form.errors.takeaway"
          />
          <FormTextArea
            v-model="form.notes"
            rows="3"
            :label="$t('Notes for the director')"
            :placeholder="$t('Anything that must or must not happen.')"
            :error="form.errors.notes"
          />
        </section>
      </div>

      <aside class="space-y-8 lg:sticky lg:top-8 lg:self-start">
        <section class="space-y-6 rounded-lg border border-border bg-card p-6">
          <SectionHeading :title="$t('Overrides')" :description="$t('Leave empty to inherit from the project.')" />
          <FormSelect
            v-model="form.purposeOverride"
            :label="$t('Purpose')"
            :options="purposes"
            :placeholder="$t('Inherit: :purpose', { purpose: project.purposeLabel })"
            :error="form.errors.purposeOverride"
          />
          <FormSelect
            v-model="form.aspectRatioOverride"
            :label="$t('Aspect ratio')"
            :options="aspectRatios"
            :placeholder="$t('Inherit: :ratio', { ratio: project.aspectRatio })"
            :error="form.errors.aspectRatioOverride"
          />
          <FormInput
            v-model="form.duration"
            type="number"
            min="2"
            max="30"
            :label="$t('Length')"
            :placeholder="String(project.defaultDuration)"
            :error="form.errors.duration"
          >
            <template #suffix>{{ $t('sec') }}</template>
          </FormInput>
        </section>

        <div class="flex items-center gap-3">
          <Button type="submit" :disabled="form.processing">
            <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
            {{ shot.id ? $t('Save shot') : $t('Add shot') }}
          </Button>
          <Button as-child variant="ghost">
            <Link :href="shot.links?.view ?? project.links?.view ?? '#'">{{ $t('Cancel') }}</Link>
          </Button>
        </div>
      </aside>
    </form>
  </Page>
</template>
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import FormInput from '@public:components/Form/Input.vue'
import FormSelect from '@public:components/Form/Select.vue'
import FormTextArea from '@public:components/Form/TextArea.vue'
import Page from '@public:components/Page.vue'
import SectionHeading from '@public:components/SectionHeading.vue'
import { store } from '@routes/public/shots'
import { Button } from '@shared:ui/button'
import { LoaderCircle } from 'lucide-vue-next'

defineOptions({
  layout: AppLayout,
})

const props = defineProps<Inertia.Pages.Shots.Update>()

type ShotForm = Omit<Inertia.Requests.Shots.Store, 'notes' | 'purposeOverride' | 'aspectRatioOverride' | 'duration'> & {
  notes: string
  purposeOverride: string | null
  aspectRatioOverride: string | null
  duration: number | string | null
}

const form = useForm<ShotForm>({
  title: props.shot.title ?? '',
  subject: props.shot.subject ?? '',
  action: props.shot.action ?? '',
  takeaway: props.shot.takeaway ?? '',
  notes: props.shot.notes ?? '',
  purposeOverride: props.shot.purposeOverride ?? null,
  aspectRatioOverride: props.shot.aspectRatioOverride ?? null,
  duration: props.shot.duration ?? null,
})

const submit = () => {
  form
    .transform((data) => ({
      ...data,
      duration: data.duration === '' ? null : data.duration,
    }))
    .post(props.shot.links?.update ?? store.url({ project: props.project.id }))
}
</script>
