<template>
  <div class="w-full max-w-[85%] space-y-3 py-1">
    <p v-if="message.content" class="text-[15px] leading-relaxed whitespace-pre-wrap">{{ message.content }}</p>

    <p v-if="message.suggestions.length === 0" class="text-sm text-muted-foreground">
      {{
        $t('No usable photos found. Add your own with the + button, or ask the director to search for something else.')
      }}
    </p>

    <ul v-else class="grid grid-cols-3 gap-2 sm:grid-cols-4" :aria-label="$t('Suggested photos')">
      <li v-for="suggestion in message.suggestions" :key="suggestion.id">
        <label
          class="group relative block aspect-square cursor-pointer overflow-hidden rounded-lg bg-muted has-checked:ring-2 has-checked:ring-signal has-disabled:cursor-default has-focus-visible:ring-2 has-focus-visible:ring-ring"
          :title="suggestion.title ?? suggestion.domain ?? ''"
        >
          <input
            v-model="selected"
            type="checkbox"
            class="peer sr-only"
            :value="suggestion.id"
            :disabled="disabled || suggestion.picked"
            :aria-label="suggestion.title ?? $t('Photo')"
          />
          <img
            :src="suggestion.thumbnailUrl"
            :alt="suggestion.title ?? ''"
            class="size-full object-cover transition-opacity"
            :class="suggestion.picked && 'opacity-40'"
            loading="lazy"
            referrerpolicy="no-referrer"
          />
          <span
            v-if="!suggestion.picked"
            class="absolute top-1.5 right-1.5 flex size-5 items-center justify-center rounded-full border text-white"
            :class="selected.includes(suggestion.id) ? 'border-signal bg-signal' : 'border-white/80 bg-black/40'"
            aria-hidden="true"
          >
            <Check v-if="selected.includes(suggestion.id)" class="size-3" />
          </span>
          <span
            v-if="suggestion.picked"
            class="absolute inset-x-0 bottom-0 bg-black/60 px-2 py-1 text-[11px] text-white"
          >
            {{ $t('Added') }}
          </span>
          <span
            v-else-if="suggestion.fromWebsite"
            class="absolute inset-x-0 bottom-0 truncate bg-black/50 px-2 py-1 text-[11px] text-white"
          >
            {{ suggestion.domain }}
          </span>
        </label>
      </li>
    </ul>

    <div v-if="message.suggestions.length > 0 && !allPicked" class="flex items-center gap-3">
      <Button type="button" size="sm" :disabled="disabled || selected.length === 0" @click="add">
        <Plus class="size-3.5" />
        {{
          selected.length === 0
            ? $t('Tick the photos that fit')
            : $t('Add :count photos', { count: String(selected.length) })
        }}
      </Button>
      <span class="text-xs text-muted-foreground">{{ $t('Found on the client’s website first, then the web.') }}</span>
    </div>
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { Button } from '@shared:ui/button'
import { Check, Plus } from 'lucide-vue-next'
import { computed, ref } from 'vue'

import type { ChatMessage } from './types'

const props = defineProps<{
  message: Extract<ChatMessage, { kind: 'photo-gallery' }>
  disabled?: boolean
}>()

const emit = defineEmits<{
  pick: [message: Extract<ChatMessage, { kind: 'photo-gallery' }>, ids: string[]]
}>()

const selected = ref<string[]>([])

const allPicked = computed(() => props.message.suggestions.every((suggestion) => suggestion.picked))

const add = () => {
  emit('pick', props.message, [...selected.value])
  selected.value = []
}
</script>
