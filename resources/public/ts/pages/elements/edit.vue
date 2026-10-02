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
          <div class="relative aspect-square overflow-hidden rounded-xl border border-border bg-paper-deep">
            <img v-if="chosen" :src="chosen.imageUrl" :alt="element?.name" class="size-full object-cover" />
            <Placeholder v-else class="size-full rounded-none border-0 bg-paper-deep" />
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

          <p v-if="element?.renderError && !generating" class="text-sm text-destructive">{{ element.renderError }}</p>

          <Button type="submit" class="w-full" :disabled="generating || !canSave">
            <LoaderCircle v-if="generating" class="size-4 animate-spin" />
            <Wand2 v-else class="size-4" />
            {{ buttonLabel }}
          </Button>
        </form>
      </div>
    </Page>
  </div>
</template>
<script setup lang="ts">
import { Link, router, useForm, usePoll } from '@inertiajs/vue3'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import Placeholder from '@public:components/editor/Placeholder.vue'
import InputError from '@public:components/Form/InputError.vue'
import Page from '@public:components/Page.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Input } from '@shared:ui/input'
import { Label } from '@shared:ui/label'
import { Textarea } from '@shared:ui/textarea'
import { ArrowLeft, LoaderCircle, Wand2 } from 'lucide-vue-next'
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
})

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

const generating = computed(() => form.processing || Boolean(props.element?.rendering))

const canSave = computed(() => form.name.trim() !== '' && form.description.trim() !== '')

const buttonLabel = computed(() => {
  if (generating.value) return $t('Generating…')
  if (!props.element) return $t('Create and draw')
  if (form.change.trim() !== '') return $t('Apply change')

  return $t('Save')
})

const save = () =>
  form.post(props.saveUrl, {
    preserveScroll: true,
    onSuccess: () => form.reset('change'),
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
