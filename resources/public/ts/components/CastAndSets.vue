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
        <li v-for="element in ofType(type.value)" :key="element.id" class="aspect-square">
          <Link
            :href="element.url"
            :title="element.name"
            class="relative block size-full overflow-hidden rounded-xl border border-border bg-paper-deep transition-colors hover:border-muted-foreground/60"
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
              v-if="element.rendering"
              class="absolute inset-0 flex items-center justify-center bg-background/40 text-signal"
            >
              <LoaderCircle class="size-5 animate-spin" />
            </span>
          </Link>
        </li>

        <li class="aspect-square">
          <Link
            :href="`${createUrl}?type=${type.value}`"
            class="flex size-full items-center justify-center rounded-xl border border-dashed border-border bg-card/60 text-muted-foreground transition-colors hover:border-muted-foreground/60 hover:text-foreground"
            :aria-label="$t('Add :type', { type: type.label.toLowerCase() })"
            :title="$t('Add :type', { type: type.label.toLowerCase() })"
          >
            <Plus class="size-6" />
          </Link>
        </li>
      </ul>
    </div>
  </section>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import Placeholder from '@public:components/editor/Placeholder.vue'
import { Box, LoaderCircle, MapPin, Plus, User } from 'lucide-vue-next'

type ElementType = { value: string; label: string; plural: string }

type CastElement = {
  id: string
  type: string
  name: string
  imageUrl: string | null
  rendering: boolean
  url: string
}

const props = defineProps<{
  elements: CastElement[]
  types: ElementType[]
  createUrl: string
}>()

const ofType = (type: string) => props.elements.filter((element) => element.type === type)

const icons: Record<string, typeof User> = { person: User, place: MapPin, object: Box }

const iconFor = (type: string) => icons[type] ?? Box
</script>
