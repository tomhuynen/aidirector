<template>
  <section class="space-y-6">
    <div class="space-y-1">
      <h2 class="text-xl font-semibold">{{ $t('Cast & sets') }}</h2>
      <p class="text-sm text-muted-foreground">
        {{ $t('People, places and objects that look the same in every shot.') }}
      </p>
    </div>

    <div v-for="type in types" :key="type.value" class="space-y-3">
      <h3 class="flex items-center gap-2 text-sm font-semibold">
        <component :is="iconFor(type.value)" class="size-4 text-muted-foreground" />
        {{ type.plural }}
      </h3>

      <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
        <li
          v-for="element in ofType(type.value)"
          :key="element.id"
          class="aspect-square overflow-hidden rounded-xl border border-border bg-paper-deep"
          :title="element.name"
        >
          <img
            v-if="element.imageUrl"
            :src="element.imageUrl"
            :alt="element.name"
            class="size-full object-cover"
            loading="lazy"
          />
          <Placeholder v-else class="size-full rounded-none border-0 bg-background">
            <LoaderCircle v-if="waiting" class="size-5 animate-spin text-signal" />
          </Placeholder>
        </li>

        <li class="aspect-square">
          <button
            type="button"
            class="flex size-full items-center justify-center rounded-xl border border-dashed border-border bg-card/60 text-muted-foreground transition-colors hover:border-muted-foreground/60 hover:text-foreground"
            :aria-label="$t('Add :type', { type: type.label.toLowerCase() })"
            :title="$t('Add :type', { type: type.label.toLowerCase() })"
            @click="startAdding(type.value)"
          >
            <Plus class="size-6" />
          </button>
        </li>
      </ul>
    </div>

    <Dialog v-model:open="open">
      <DialogContent>
        <form class="space-y-5" @submit.prevent="submit">
          <DialogHeader>
            <DialogTitle>{{ $t('Add :type', { type: adding?.label.toLowerCase() ?? '' }) }}</DialogTitle>
            <DialogDescription>
              {{ $t('Describe how it looks. A reference image is drawn in the project style.') }}
            </DialogDescription>
          </DialogHeader>

          <div class="space-y-1.5">
            <Label for="element-name">{{ $t('Name') }}</Label>
            <Input id="element-name" v-model="form.name" maxlength="120" :placeholder="namePlaceholder" />
            <InputError :message="form.errors.name" />
          </div>

          <div class="space-y-1.5">
            <Label for="element-description">{{ $t('Description') }}</Label>
            <Textarea
              id="element-description"
              v-model="form.description"
              rows="4"
              maxlength="500"
              :placeholder="descriptionPlaceholder"
            />
            <InputError :message="form.errors.description ?? form.errors.type" />
          </div>

          <DialogFooter class="gap-2">
            <DialogClose as-child>
              <Button type="button" variant="secondary">{{ $t('Cancel') }}</Button>
            </DialogClose>
            <Button
              type="submit"
              :disabled="form.processing || form.name.trim() === '' || form.description.trim() === ''"
            >
              <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
              <Plus v-else class="size-4" />
              {{ $t('Add') }}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  </section>
</template>
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import Placeholder from '@public:components/editor/Placeholder.vue'
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
import { Textarea } from '@shared:ui/textarea'
import { Box, LoaderCircle, MapPin, Plus, User } from 'lucide-vue-next'
import { computed, onBeforeUnmount, ref } from 'vue'

type ElementType = { value: string; label: string; plural: string }

type CastElement = {
  id: string
  type: string
  name: string
  description: string
  imageUrl: string | null
}

const props = defineProps<{
  elements: CastElement[]
  types: ElementType[]
  storeUrl: string
}>()

const ofType = (type: string) => props.elements.filter((element) => element.type === type)

const icons: Record<string, typeof User> = { person: User, place: MapPin, object: Box }

const iconFor = (type: string) => icons[type] ?? Box

const open = ref(false)
const adding = ref<ElementType | null>(null)
const form = useForm({ type: '', name: '', description: '' })

const placeholders: Record<string, { name: string; description: string }> = {
  person: {
    name: $t('For example: Site manager'),
    description: $t('For example: a woman in her forties with short grey hair, in a hi-vis vest over a navy jacket.'),
  },
  place: {
    name: $t('For example: Main gate'),
    description: $t('For example: a grey steel gatehouse with a yellow barrier and a blue sign.'),
  },
  object: {
    name: $t('For example: Harbour tug'),
    description: $t('For example: a red and white tug with a black hull and a white wheelhouse.'),
  },
}

const namePlaceholder = computed(() => placeholders[form.type]?.name ?? '')
const descriptionPlaceholder = computed(() => placeholders[form.type]?.description ?? '')

const startAdding = (type: string) => {
  adding.value = props.types.find((candidate) => candidate.value === type) ?? null
  form.reset()
  form.clearErrors()
  form.type = type
  open.value = true
}

/**
 * After adding, the reference image is drawn in the background; the list
 * refreshes until every element has its image, for at most a minute and a half.
 */
const waiting = ref(false)
let timer: ReturnType<typeof setInterval> | undefined

const stopWaiting = () => {
  if (timer) clearInterval(timer)
  timer = undefined
  waiting.value = false
}

const waitForImages = () => {
  stopWaiting()
  waiting.value = true

  let rounds = 0

  timer = setInterval(() => {
    rounds++

    if (rounds > 30 || props.elements.every((element) => element.imageUrl)) {
      stopWaiting()

      return
    }

    router.reload({ only: ['elements'] })
  }, 3000)
}

onBeforeUnmount(stopWaiting)

const submit = () =>
  form.post(props.storeUrl, {
    preserveScroll: true,
    onSuccess: () => {
      open.value = false
      waitForImages()
    },
  })
</script>
