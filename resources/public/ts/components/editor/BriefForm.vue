<template>
  <form class="flex min-h-0 flex-1 flex-col overflow-y-auto p-8" @submit.prevent="submit">
    <div class="flex w-full max-w-[700px] flex-col">
      <header class="space-y-2">
        <h2 class="text-3xl font-semibold">{{ $t('What should this shot tell?') }}</h2>
        <p class="text-lg text-muted-foreground">
          {{ $t('Give the takeaway. The storyteller comes up with possible storylines.') }}
        </p>
      </header>

      <div class="mt-8 grid gap-x-6 gap-y-6 lg:grid-cols-[1fr_12rem]">
        <BriefField
          v-model="form.takeaway"
          :label="$t('Takeaway')"
          :max="1000"
          rows="3"
          required
          :placeholder="$t('Smoking is prohibited on the shipyard.')"
          :error="form.errors.takeaway"
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
            :placeholder="$t('Automatic')"
            class="block w-full rounded-lg border border-input bg-background px-4 py-3 text-[15px] placeholder:text-muted-foreground/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
          />
          <InputError :message="form.errors.duration" />
        </div>
      </div>

      <BriefField
        v-model="form.notes"
        class="mt-6"
        :label="$t('Context')"
        :hint="$t('optional')"
        :max="2000"
        rows="3"
        :placeholder="$t('Anything the storyteller should know, such as where it happens or what went before.')"
        :error="form.errors.notes"
      />

      <fieldset v-if="elements.length > 0 || project.links?.elementsCreate" class="mt-6 space-y-3">
        <legend class="text-[15px] font-medium">{{ $t('Do you have a preference for who or where?') }}</legend>
        <div class="flex gap-2">
          <Button type="button" :variant="choosing ? 'outline' : 'default'" @click="choosing = false">
            {{ $t('No, surprise me') }}
          </Button>
          <Button type="button" :variant="choosing ? 'default' : 'outline'" @click="choosing = true">
            {{ $t('Yes, let me pick') }}
          </Button>
        </div>

        <template v-if="choosing">
          <p class="text-sm text-muted-foreground">
            {{ $t('Every storyline uses the people, places and objects you pick.') }}
          </p>
          <ElementPicker
            v-model="form.preferredElements"
            :elements="elements"
            :types="elementTypes"
            :create-url="project.links?.elementsCreate"
          />
          <InputError :message="form.errors.preferredElements" />
        </template>
      </fieldset>

      <footer class="mt-8 flex items-center justify-end gap-3">
        <Button v-if="cancelUrl" as-child variant="ghost">
          <Link :href="cancelUrl">{{ $t('Cancel') }}</Link>
        </Button>
        <Button type="submit" size="lg" :disabled="form.processing">
          <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
          {{ $t('Continue') }}
          <ArrowRight class="size-4" />
        </Button>
      </footer>
    </div>
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
import { ref, useId } from 'vue'

import ElementPicker from '../ElementPicker.vue'
import BriefField from './BriefField.vue'

type BriefForm = {
  takeaway: string
  notes: string
  duration: number | string | null
  preferredElements: string[]
}

const props = defineProps<{
  project: Inertia.Pages.Shots.Update['project']
  shot: Inertia.Pages.Shots.Update['shot']
  elements: Inertia.Pages.Shots.Update['elements']
  elementTypes: Inertia.Pages.Shots.Update['elementTypes']
  cancelUrl?: string
}>()

const durationId = useId()

// Debug default for a new shot, so the storyline flow can be tried with one click. Remove before release.
const debugTakeaway = 'Smoking is prohibited on the shipyard.'

const form = useForm<BriefForm>({
  takeaway: props.shot.id ? props.shot.takeaway : debugTakeaway,
  notes: props.shot.notes ?? '',
  duration: props.shot.duration ?? null,
  preferredElements: [...(props.shot.preferredElements ?? [])],
})

const choosing = ref(form.preferredElements.length > 0)

const submit = () => {
  form
    .transform((data) => ({
      ...data,
      duration: data.duration === '' ? null : data.duration,
      preferredElements: choosing.value ? data.preferredElements : [],
    }))
    .post(props.shot.links?.update ?? store.url({ project: props.project.id }))
}
</script>
