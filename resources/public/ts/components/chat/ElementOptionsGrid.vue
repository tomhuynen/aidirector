<template>
  <div class="w-full max-w-[85%] space-y-3 py-1">
    <div class="flex items-baseline justify-between gap-3">
      <h3 class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ message.label }}</h3>
      <p v-if="message.status === 'ready'" class="text-xs text-muted-foreground">
        {{ $t('Tick the ones to keep in the cast and sets') }}
      </p>
    </div>

    <p v-if="message.status === 'failed'" class="text-sm text-destructive">
      {{ message.error ?? $t('The suggestions could not be written. Please ask again.') }}
    </p>

    <ul
      v-else-if="message.status === 'suggesting'"
      class="grid grid-cols-3 gap-3 sm:grid-cols-4"
      :aria-label="$t('Writing suggestions')"
    >
      <li v-for="n in 12" :key="n" class="space-y-2">
        <Skeleton class="aspect-square w-full rounded-lg" />
        <Skeleton class="h-3 w-2/3" />
      </li>
    </ul>

    <ul v-else class="grid grid-cols-3 gap-3 sm:grid-cols-4" :aria-label="message.label">
      <li v-for="option in visibleOptions" :key="option.id">
        <label
          class="flex h-full flex-col gap-2 rounded-lg border bg-card p-2 has-checked:border-signal has-checked:ring-2 has-checked:ring-signal/40 has-focus-visible:ring-2 has-focus-visible:ring-ring"
          :class="[
            option.picked ? 'border-signal' : 'border-border',
            selectable(option) ? 'cursor-pointer' : 'cursor-default',
          ]"
          :title="option.description"
        >
          <input
            v-model="selected"
            type="checkbox"
            class="sr-only"
            :value="option.id"
            :disabled="!selectable(option)"
            :aria-label="option.name"
          />
          <span class="relative block aspect-square overflow-hidden rounded-md bg-muted">
            <img
              v-if="option.thumbnailUrl"
              :src="option.thumbnailUrl"
              :alt="option.name"
              class="size-full object-cover"
              loading="lazy"
            />
            <span v-else-if="option.status === 'pending'" class="flex size-full items-center justify-center">
              <LoaderCircle class="size-5 animate-spin text-signal" />
            </span>
            <span v-else class="flex size-full items-center justify-center px-2 text-center text-xs text-destructive">
              {{ $t('Could not be drawn') }}
            </span>
            <span
              v-if="message.status === 'ready' && option.status === 'ready'"
              class="absolute top-1.5 right-1.5 flex size-5 items-center justify-center rounded-full border text-white"
              :class="selected.includes(option.id) ? 'border-signal bg-signal' : 'border-white/80 bg-black/40'"
              aria-hidden="true"
            >
              <Check v-if="selected.includes(option.id)" class="size-3" />
            </span>
            <span
              v-if="option.fromPhoto"
              class="absolute bottom-1.5 left-1.5 rounded bg-black/60 px-1.5 py-0.5 text-[10px] text-white"
            >
              {{ $t('From your photo') }}
            </span>
          </span>
          <span class="space-y-0.5">
            <span class="block text-sm font-semibold">{{ option.name }}</span>
            <span class="line-clamp-2 block text-xs text-muted-foreground">{{ option.description }}</span>
          </span>
        </label>
      </li>
    </ul>

    <div v-if="message.status === 'ready'" class="flex flex-wrap items-center gap-2">
      <Button type="button" size="sm" :disabled="disabled || selected.length === 0" @click="keep">
        <Check class="size-3.5" />
        {{
          selected.length === 0 ? $t('Tick the ones to keep') : $t('Keep :count', { count: String(selected.length) })
        }}
      </Button>
      <Button type="button" size="sm" variant="outline" :disabled="disabled" @click="emit('skip', message)">
        {{ $t('Skip :label', { label: message.label.toLowerCase() }) }}
      </Button>
      <span v-if="rendering > 0" class="flex items-center gap-1.5 text-xs text-muted-foreground">
        <LoaderCircle class="size-3.5 animate-spin" />
        {{ $t(':count still drawing', { count: String(rendering) }) }}
      </span>
    </div>
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { Button } from '@shared:ui/button'
import { Skeleton } from '@shared:ui/skeleton'
import { Check, LoaderCircle } from 'lucide-vue-next'
import { computed, ref } from 'vue'

import type { ChatMessage, ElementOptionTile } from './types'

type ElementMessage = Extract<ChatMessage, { kind: 'element-options' }>

const props = defineProps<{
  message: ElementMessage
  disabled?: boolean
}>()

const emit = defineEmits<{
  pick: [message: ElementMessage, ids: string[]]
  skip: [message: ElementMessage]
}>()

const selected = ref<string[]>([])

/** Once picked, only the kept ones remain. */
const visibleOptions = computed(() =>
  props.message.status === 'picked' ? props.message.options.filter((option) => option.picked) : props.message.options,
)

const rendering = computed(() => props.message.options.filter((option) => option.status === 'pending').length)

const selectable = (option: ElementOptionTile) =>
  !props.disabled && props.message.status === 'ready' && option.status === 'ready'

const keep = () => emit('pick', props.message, [...selected.value])
</script>
