<template>
  <!-- The cast and sets to tick, each with its picture; one being drawn fades behind a loader until its new picture is there. -->
  <ul class="grid grid-cols-3 gap-2">
    <li v-for="id in ids" :key="id">
      <label
        class="relative flex cursor-pointer flex-col gap-1 rounded-lg border border-border bg-card p-1.5 has-checked:border-signal has-checked:ring-2 has-checked:ring-signal/40 has-disabled:cursor-default"
      >
        <input v-model="picked" type="checkbox" class="sr-only" :value="id" :disabled="disabled" />
        <span class="relative block aspect-square w-full overflow-hidden rounded-md bg-muted">
          <img
            v-if="elementOf(id)?.imageUrl"
            :src="elementOf(id)?.imageUrl ?? undefined"
            :alt="elementOf(id)?.name"
            :class="cn('size-full object-cover transition-opacity', elementOf(id)?.rendering && 'opacity-30')"
          />
          <span v-if="elementOf(id)?.rendering" class="absolute inset-0 flex items-center justify-center">
            <LoaderCircle class="size-5 animate-spin text-signal" />
          </span>
        </span>
        <span class="truncate text-xs font-medium">
          {{ elementOf(id)?.name }}
          <span v-if="elementOf(id)?.rendering" class="text-muted-foreground"> · {{ $t('drawing') }}</span>
        </span>
      </label>
    </li>
  </ul>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { LoaderCircle } from 'lucide-vue-next'

const props = defineProps<{
  /** The ids of the cast and sets offered. */
  ids: string[]
  elements: { id: string; name: string; imageUrl: string | null; rendering?: boolean }[]
  disabled?: boolean
}>()

/** The ids ticked. */
const picked = defineModel<string[]>({ default: () => [] })

const elementOf = (id: string) => props.elements.find((element) => element.id === id)
</script>
