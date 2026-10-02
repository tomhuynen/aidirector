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
            :placeholder="String(project.defaultDuration)"
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

      <fieldset v-if="elements.length > 0" class="mt-6 space-y-3">
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
          <div v-for="type in typesInUse" :key="type.value" class="space-y-2">
            <h3 class="flex items-center gap-2 text-sm font-semibold">
              <component :is="iconFor(type.value)" class="size-4 text-muted-foreground" />
              {{ type.plural }}
            </h3>
            <ul class="grid grid-cols-4 gap-3 sm:grid-cols-6">
              <li v-for="element in ofType(type.value)" :key="element.id" class="aspect-square">
                <button
                  type="button"
                  :title="element.name"
                  :aria-label="element.name"
                  :aria-pressed="isPicked(element.id)"
                  :class="
                    cn(
                      'relative block size-full overflow-hidden rounded-xl border border-border bg-paper-deep transition',
                      isPicked(element.id) ? 'border-signal ring-4 ring-signal/40' : 'hover:border-muted-foreground/60',
                    )
                  "
                  @click="toggle(element.id)"
                >
                  <img
                    v-if="element.imageUrl"
                    :src="element.imageUrl"
                    :alt="element.name"
                    class="size-full object-cover"
                    loading="lazy"
                  />
                  <Placeholder v-else class="size-full rounded-none border-0 bg-background" />
                  <span
                    v-if="isPicked(element.id)"
                    class="absolute top-1.5 left-1.5 flex size-6 items-center justify-center rounded-full bg-signal text-primary-foreground"
                  >
                    <Check class="size-3.5" />
                  </span>
                </button>
              </li>
            </ul>
          </div>
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
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { ArrowRight, Box, Check, LoaderCircle, MapPin, User } from 'lucide-vue-next'
import { computed, ref, useId } from 'vue'

import BriefField from './BriefField.vue'
import Placeholder from './Placeholder.vue'

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

const ofType = (type: string) => props.elements.filter((element) => element.type === type)

/**
 * Only the categories that have something to pick.
 */
const typesInUse = computed(() => props.elementTypes.filter((type) => ofType(type.value).length > 0))

const icons: Record<string, typeof User> = { person: User, place: MapPin, object: Box }

const iconFor = (type: string) => icons[type] ?? Box

const choosing = ref(form.preferredElements.length > 0)

const isPicked = (id: string) => form.preferredElements.includes(id)

const toggle = (id: string) => {
  form.preferredElements = isPicked(id)
    ? form.preferredElements.filter((picked) => picked !== id)
    : [...form.preferredElements, id]
}

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
