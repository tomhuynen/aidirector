<template>
  <!-- The cast and sets in one keyframe as small pictures: places first, then people, then objects. -->
  <div class="flex flex-wrap items-center gap-2" role="group" :aria-label="$t('In this keyframe')">
    <div v-for="element in picked" :key="element.id" class="group/thumb relative">
      <img
        v-if="element.imageUrl"
        :src="element.imageUrl"
        :alt="element.name"
        :title="element.name"
        class="size-12 rounded-lg border border-border object-cover"
      />
      <span v-else :title="element.name" class="block size-12 rounded-lg border border-border bg-secondary" />
      <button
        type="button"
        class="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full border border-border bg-background text-muted-foreground transition-colors hover:text-foreground"
        :aria-label="$t('Remove :name', { name: element.name })"
        :title="$t('Remove :name', { name: element.name })"
        @click="remove(element.id)"
      >
        <X class="size-3" />
      </button>
    </div>

    <Dialog>
      <DialogTrigger as-child>
        <button
          type="button"
          class="flex size-12 items-center justify-center rounded-lg border border-dashed border-border bg-card/60 text-muted-foreground transition-colors hover:border-muted-foreground/60 hover:text-foreground"
          :aria-label="$t('Choose who and what is in this keyframe')"
          :title="$t('Choose who and what is in this keyframe')"
        >
          <Plus class="size-5" />
        </button>
      </DialogTrigger>
      <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{{ $t('Who and what is in this keyframe?') }}</DialogTitle>
          <DialogDescription>{{
            $t('Their pictures go to the image model, so they are drawn as they look.')
          }}</DialogDescription>
        </DialogHeader>
        <ElementPicker v-model="ids" :elements="elements" :types="orderedTypes" />
        <DialogFooter>
          <DialogClose as-child>
            <Button type="button">{{ $t('Done') }}</Button>
          </DialogClose>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import ElementPicker from '@public:components/ElementPicker.vue'
import { Button } from '@shared:ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@shared:ui/dialog'
import { Plus, X } from 'lucide-vue-next'
import { computed } from 'vue'

type CastElement = { id: string; type: string; name: string; imageUrl: string | null }

const props = defineProps<{
  elements: CastElement[]
  types: { value: string; label: string; plural: string }[]
}>()

const ids = defineModel<string[]>({ required: true })

/** Places first, then people, then objects. */
const ORDER = ['place', 'person', 'object']

const rank = (type: string) => (ORDER.includes(type) ? ORDER.indexOf(type) : ORDER.length)

const orderedTypes = computed(() => [...props.types].sort((a, b) => rank(a.value) - rank(b.value)))

const picked = computed(() =>
  props.elements
    .filter((element) => ids.value.includes(element.id))
    .sort((a, b) => rank(a.type) - rank(b.type) || a.name.localeCompare(b.name)),
)

const remove = (id: string) => (ids.value = ids.value.filter((other) => other !== id))
</script>
