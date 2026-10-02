<template>
  <div class="space-y-6">
    <Link
      :href="project.links?.view ?? '#'"
      class="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
    >
      <ArrowLeft class="size-4" />
      {{ project.title }}
    </Link>

    <Page :eyebrow="type.plural" :title="element?.name ?? $t('New :type', { type: type.label.toLowerCase() })">
      <div class="grid gap-8 md:grid-cols-2">
        <div class="space-y-3">
          <div
            v-if="element"
            class="relative aspect-square overflow-hidden rounded-xl border border-border bg-paper-deep"
          >
            <img v-if="chosen" :src="chosen.imageUrl" :alt="element.name" class="size-full object-cover" />
            <Placeholder v-else class="size-full rounded-none border-0 bg-paper-deep" />
          </div>

          <div v-else class="space-y-2">
            <label
              for="element-photo"
              :class="
                cn(
                  'relative flex aspect-square cursor-pointer flex-col items-center justify-center gap-2 overflow-hidden rounded-xl border border-dashed border-border bg-paper-deep text-center text-muted-foreground transition-colors hover:border-muted-foreground/60 hover:text-foreground',
                  photo && 'border-solid',
                )
              "
            >
              <img
                v-if="photo?.previewUrl"
                :src="photo.previewUrl"
                :alt="$t('Photo')"
                class="absolute inset-0 size-full object-cover"
              />
              <template v-else>
                <ImageUp class="size-8" />
                <span class="text-sm font-medium">{{ $t('Upload a photo') }}</span>
                <span class="max-w-56 text-sm">{{
                  $t('Optional. The picture is drawn from it in the project style.')
                }}</span>
              </template>
              <span
                v-if="photo?.status === 'uploading'"
                class="absolute inset-0 flex items-center justify-center bg-background/40 text-signal"
              >
                <LoaderCircle class="size-6 animate-spin" />
              </span>
            </label>
            <input id="element-photo" type="file" accept="image/*" class="sr-only" @change="choosePhoto" />
            <div v-if="photo" class="flex items-center justify-between gap-3 text-sm">
              <span :class="photo.status === 'failed' ? 'text-destructive' : 'text-muted-foreground'">
                {{ photo.status === 'failed' ? photo.error : photo.name }}
              </span>
              <Button type="button" variant="ghost" size="sm" @click="uploads.remove(photo.key)">
                <X class="size-4" />
                {{ $t('Remove') }}
              </Button>
            </div>
            <InputError :message="form.errors.photo" />
          </div>

          <div v-if="element && element.versions.length > 0" class="space-y-2">
            <p class="text-sm text-muted-foreground">
              {{ $t('Versions') }}
              <template v-if="element.versions.length === 1">
                · {{ $t('earlier pictures appear here after a change') }}</template
              >
            </p>
            <ul class="flex flex-wrap gap-2" :aria-label="$t('Versions')">
              <li v-for="(version, i) in element.versions" :key="version.id">
                <button
                  type="button"
                  :class="
                    cn(
                      'relative block size-16 overflow-hidden rounded-lg border border-border transition-colors hover:border-muted-foreground/60',
                      version.chosen && 'border-signal ring-2 ring-signal/40',
                    )
                  "
                  :title="version.request ?? $t('Version :n', { n: String(i + 1) })"
                  :disabled="version.chosen || generating || choosing"
                  @click="choose(version.id)"
                >
                  <img
                    :src="version.thumbnailUrl"
                    :alt="$t('Version :n', { n: String(i + 1) })"
                    class="size-full object-cover"
                  />
                </button>
              </li>
            </ul>
          </div>
        </div>

        <form class="space-y-5" @submit.prevent="save">
          <div class="space-y-1.5">
            <Label for="element-name">{{ $t('Name') }}</Label>
            <Input id="element-name" v-model="form.name" maxlength="120" :disabled="generating" />
            <InputError :message="form.errors.name" />
          </div>

          <div class="space-y-1.5">
            <Label for="element-description">{{ $t('Description') }}</Label>
            <Textarea
              id="element-description"
              v-model="form.description"
              rows="5"
              maxlength="500"
              :disabled="generating"
              class="leading-relaxed"
            />
            <InputError :message="form.errors.description" />
            <p class="text-sm text-muted-foreground">
              {{ $t('How it looks. A new description draws the image again.') }}
            </p>
          </div>

          <div v-if="element?.imageUrl" class="space-y-1.5">
            <Label for="element-change">{{ $t('What should change?') }}</Label>
            <Textarea
              id="element-change"
              v-model="form.change"
              rows="3"
              maxlength="500"
              :disabled="generating"
              :placeholder="$t('For example: give him glasses')"
              class="leading-relaxed"
            />
            <InputError :message="form.errors.change" />
          </div>

          <fieldset v-if="elements.length > 0 && (!element || element.imageUrl)" class="space-y-3">
            <legend class="text-sm font-medium">{{ $t('Add existing elements to it?') }}</legend>
            <div class="flex gap-2">
              <Button
                type="button"
                :variant="including ? 'outline' : 'default'"
                :disabled="generating"
                @click="including = false"
              >
                {{ $t('No') }}
              </Button>
              <Button
                type="button"
                :variant="including ? 'default' : 'outline'"
                :disabled="generating"
                @click="including = true"
              >
                {{ $t('Yes, let me pick') }}
              </Button>
            </div>
            <template v-if="including">
              <p class="text-sm text-muted-foreground">
                {{ $t('They are drawn into it, such as an object a person holds. Up to three.') }}
              </p>
              <ElementPicker v-model="form.includes" :elements="elements" :types="elementTypes" />
              <InputError :message="form.errors.includes" />
            </template>
          </fieldset>

          <p v-if="element?.renderError && !generating" class="text-sm text-destructive">{{ element.renderError }}</p>

          <Button type="submit" class="w-full" :disabled="generating || !canSave">
            <LoaderCircle v-if="generating" class="size-4 animate-spin" />
            <Wand2 v-else class="size-4" />
            {{ buttonLabel }}
          </Button>

          <ConfirmDelete
            v-if="element"
            :action="element.destroyUrl"
            :title="$t('Delete :name?', { name: element.name })"
            :description="
              $t(
                'It is removed from the cast and sets with all its pictures. Keyframes that show it keep their images.',
              )
            "
          >
            <template #trigger>
              <Button
                type="button"
                variant="outline"
                class="w-full text-destructive hover:text-destructive"
                :disabled="generating"
              >
                <Trash2 class="size-4" />
                {{ $t('Delete') }}
              </Button>
            </template>
          </ConfirmDelete>
        </form>
      </div>
    </Page>
  </div>
</template>
<script setup lang="ts">
import { Link, router, useForm, usePoll } from '@inertiajs/vue3'
import { useUploads } from '@public/ts/composables/useUploads'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import ConfirmDelete from '@public:components/ConfirmDelete.vue'
import Placeholder from '@public:components/editor/Placeholder.vue'
import ElementPicker from '@public:components/ElementPicker.vue'
import InputError from '@public:components/Form/InputError.vue'
import Page from '@public:components/Page.vue'
import { store as uploadStore } from '@routes/public/uploads'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Input } from '@shared:ui/input'
import { Label } from '@shared:ui/label'
import { Textarea } from '@shared:ui/textarea'
import { ArrowLeft, ImageUp, LoaderCircle, Trash2, Wand2, X } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

defineOptions({
  layout: AppLayout,
})

/**
 * One page for both: on create the element is null and the type comes from the plus tile.
 */
const props = defineProps<Inertia.Pages.Projects.Elements.View>()

const form = useForm({
  type: props.type.value,
  name: props.element?.name ?? '',
  description: props.element?.description ?? '',
  change: '',
  photo: null as string | null,
  includes: [] as string[],
})

const including = ref(false)

/** On create, an optional photo of the real thing; it is staged first and claimed on save. */
const uploads = useUploads({ url: uploadStore.url() })
const photo = computed(() => uploads.pending.value[0] ?? null)

const choosePhoto = (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''

  if (!file) return

  uploads.pending.value.forEach((pending) => uploads.remove(pending.key))
  void uploads.add([file])
}

const chosen = computed(() => props.element?.versions.find((version) => version.chosen) ?? null)

const choosing = ref(false)

/**
 * Makes an earlier version the element's picture again.
 */
const choose = (version: number) => {
  if (!props.element) return

  choosing.value = true
  router.post(
    props.element.versionUrl,
    { version },
    { preserveScroll: true, only: ['element'], onFinish: () => (choosing.value = false) },
  )
}

const generating = computed(() => form.processing || Boolean(props.element?.rendering) || uploads.busy.value)

const canSave = computed(() => form.name.trim() !== '' && form.description.trim() !== '')

const buttonLabel = computed(() => {
  if (generating.value) return $t('Generating…')
  if (!props.element) return $t('Create and draw')
  if (form.change.trim() !== '' || (including.value && form.includes.length > 0)) return $t('Apply change')

  return $t('Save')
})

const save = () =>
  form
    .transform((data) => ({
      ...data,
      photo: photo.value?.status === 'ready' ? photo.value.id : null,
      includes: including.value ? data.includes : [],
    }))
    .post(props.saveUrl, {
      preserveScroll: true,
      onSuccess: () => {
        form.reset('change', 'includes')
        including.value = false
      },
    })

/**
 * While the image is being drawn, the page refreshes the element until it is done.
 */
const { start, stop } = usePoll(3000, { only: ['element'] }, { autoStart: false })

watch(
  () => props.element?.rendering,
  (rendering) => (rendering ? start() : stop()),
  { immediate: true },
)
</script>
