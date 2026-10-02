<template>
  <div class="space-y-4">
    <div v-for="type in types" :key="type.value" class="space-y-2">
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
        <li v-if="createUrl" class="aspect-square">
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
  </div>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import Placeholder from '@public:components/editor/Placeholder.vue'
import { cn } from '@shared/lib/utils'
import { Box, Check, MapPin, Plus, User } from 'lucide-vue-next'

type PickableElement = { id: string; type: string; name: string; imageUrl: string | null }
type ElementType = { value: string; label: string; plural: string }

/**
 * Picture tiles of the cast and sets per category to tick; with a create
 * URL every category ends with a plus tile to add one.
 */
const props = defineProps<{
  elements: PickableElement[]
  types: ElementType[]
  createUrl?: string
}>()

const picked = defineModel<string[]>({ required: true })

const ofType = (type: string) => props.elements.filter((element) => element.type === type)

const isPicked = (id: string) => picked.value.includes(id)

const toggle = (id: string) => {
  picked.value = isPicked(id) ? picked.value.filter((pickedId) => pickedId !== id) : [...picked.value, id]
}

const icons: Record<string, typeof User> = { person: User, place: MapPin, object: Box }

const iconFor = (type: string) => icons[type] ?? Box
</script>
