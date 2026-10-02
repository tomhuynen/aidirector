<template>
  <Page
    :eyebrow="project.id ? $t('Project') : $t('New project')"
    :title="project.id ? $t('Edit :title', { title: project.title }) : $t('Set up a project')"
    :description="$t('The purpose steers the director. The style locks the look of every keyframe.')"
  >
    <form class="grid gap-12 lg:grid-cols-[1fr_320px]" @submit.prevent="submit">
      <div class="space-y-12">
        <section class="space-y-6">
          <SectionHeading :title="$t('Basics')" />
          <FormInput v-model="form.title" required autofocus :label="$t('Title')" :error="form.errors.title" />
          <FormTextArea
            v-model="form.description"
            :label="$t('What is this for?')"
            rows="3"
            :placeholder="$t('A short description of the film and its audience.')"
            :error="form.errors.description"
          />
        </section>

        <section class="space-y-6">
          <SectionHeading
            :title="$t('Purpose')"
            :description="$t('This changes what the director considers a good shot.')"
          />
          <div class="grid gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-2">
            <label
              v-for="purpose in purposes"
              :key="purpose.value"
              class="flex cursor-pointer flex-col gap-2 bg-card p-5 transition-colors has-checked:bg-signal-soft"
            >
              <span class="flex items-center justify-between gap-3">
                <span class="font-medium">{{ purpose.label }}</span>
                <input
                  v-model="form.purpose"
                  type="radio"
                  name="purpose"
                  :value="purpose.value"
                  class="accent-signal"
                />
              </span>
              <span class="text-sm leading-relaxed text-muted-foreground">{{ purpose.summary }}</span>
            </label>
          </div>
          <InputError :message="form.errors.purpose" />
        </section>

        <section class="space-y-6">
          <SectionHeading
            :title="$t('Style guide')"
            :description="$t('Written like a brief to an illustrator. Every keyframe prompt starts from this.')"
          />
          <FormTextArea
            v-model="form.style.look"
            :label="$t('Look')"
            rows="2"
            :placeholder="$t('Clean 3D cartoon, soft shading, minimal detail')"
            :error="form.errors['style.look']"
          />
          <div class="grid gap-6 sm:grid-cols-2">
            <FormInput
              v-model="form.style.medium"
              :label="$t('Medium')"
              :placeholder="$t('3D illustration')"
              :error="form.errors['style.medium']"
            />
            <FormInput
              v-model="form.style.mood"
              :label="$t('Mood')"
              :placeholder="$t('Friendly and calm')"
              :error="form.errors['style.mood']"
            />
          </div>
          <FormTextArea
            v-model="form.style.palette"
            :label="$t('Palette')"
            rows="2"
            :placeholder="$t('Navy, red accent, warm grey background')"
            :error="form.errors['style.palette']"
          />
        </section>
      </div>

      <aside class="space-y-8 lg:sticky lg:top-8 lg:self-start">
        <section class="space-y-6 rounded-lg border border-border bg-card p-6">
          <SectionHeading :title="$t('Output')" />
          <FormSelect
            v-model="form.aspectRatio"
            :label="$t('Aspect ratio')"
            :options="aspectRatios"
            :error="form.errors.aspectRatio"
          />
          <FormInput
            v-model="form.defaultDuration"
            type="number"
            min="2"
            max="30"
            :label="$t('Default shot length')"
            :error="form.errors.defaultDuration"
          >
            <template #suffix>{{ $t('sec') }}</template>
          </FormInput>
        </section>

        <div class="flex items-center gap-3">
          <Button type="submit" :disabled="form.processing">
            <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
            {{ project.id ? $t('Save changes') : $t('Create project') }}
          </Button>
          <Button as-child variant="ghost">
            <Link :href="project.links?.view ?? index.url()">{{ $t('Cancel') }}</Link>
          </Button>
        </div>

        <ConfirmDelete
          v-if="project.id && project.can.destroy"
          :action="project.links?.destroy ?? '#'"
          :title="$t('Delete this project?')"
          :description="$t('All shots in this project are deleted with it. This cannot be undone.')"
        >
          <template #trigger>
            <Button type="button" variant="ghost" class="text-destructive hover:text-destructive">
              <Trash2 class="size-4" />
              {{ $t('Delete project') }}
            </Button>
          </template>
        </ConfirmDelete>
      </aside>
    </form>
  </Page>
</template>
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import ConfirmDelete from '@public:components/ConfirmDelete.vue'
import FormInput from '@public:components/Form/Input.vue'
import InputError from '@public:components/Form/InputError.vue'
import FormSelect from '@public:components/Form/Select.vue'
import FormTextArea from '@public:components/Form/TextArea.vue'
import Page from '@public:components/Page.vue'
import SectionHeading from '@public:components/SectionHeading.vue'
import { index, store } from '@routes/public/projects'
import { Button } from '@shared:ui/button'
import { LoaderCircle, Trash2 } from 'lucide-vue-next'

defineOptions({
  layout: AppLayout,
})

const props = defineProps<Inertia.Pages.Projects.Update>()

type ProjectForm = {
  title: string
  purpose: string
  description: string
  aspectRatio: string
  defaultDuration: number | string
  style: {
    look: string
    palette: string
    medium: string
    mood: string
    references: string[]
  }
}

const form = useForm<ProjectForm>({
  title: props.project.title ?? '',
  purpose: props.project.purpose ?? 'explainer',
  description: props.project.description ?? '',
  aspectRatio: props.project.aspectRatio ?? '9:16',
  defaultDuration: props.project.defaultDuration ?? 5,
  style: {
    look: props.project.style?.look ?? '',
    palette: props.project.style?.palette ?? '',
    medium: props.project.style?.medium ?? '',
    mood: props.project.style?.mood ?? '',
    references: props.project.style?.references ?? [],
  },
})

const submit = () => {
  form.post(props.project.links?.update ?? store.url())
}
</script>
