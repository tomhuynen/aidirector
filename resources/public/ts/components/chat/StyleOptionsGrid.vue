<template>
  <div class="w-full max-w-[85%] space-y-3 rounded-2xl rounded-bl-md bg-background/70 px-4 py-3">
    <p v-if="message.content" class="text-[15px] leading-relaxed whitespace-pre-wrap">{{ message.content }}</p>

    <ul
      class="grid grid-cols-2 gap-3"
      :aria-label="$t('Style options, round :round', { round: String(message.round) })"
    >
      <li
        v-for="option in message.options"
        :key="option.id"
        class="flex flex-col overflow-hidden rounded-xl border bg-card"
        :class="option.pinned ? 'border-signal ring-2 ring-signal/40' : 'border-border'"
      >
        <button
          type="button"
          class="relative block aspect-square w-full bg-muted text-left"
          :disabled="option.status !== 'ready' || !option.imageUrl"
          :aria-label="$t('Open :name at full size', { name: option.name })"
          @click="open(option)"
        >
          <img
            v-if="option.status === 'ready' && option.thumbnailUrl"
            :src="option.thumbnailUrl"
            :alt="option.name"
            class="size-full object-cover"
            loading="lazy"
          />
          <span
            v-else-if="option.status === 'pending'"
            class="flex size-full items-center justify-center text-muted-foreground"
          >
            <LoaderCircle class="size-6 animate-spin text-signal" />
          </span>
          <span v-else class="flex size-full items-center justify-center px-4 text-center text-sm text-destructive">
            {{ option.error ?? $t('This style could not be rendered.') }}
          </span>
        </button>

        <div class="space-y-2 p-3">
          <p class="text-sm font-semibold">{{ option.name }}</p>
          <p class="line-clamp-2 text-xs text-muted-foreground">{{ option.look }}</p>
          <div class="flex gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              class="flex-1"
              :disabled="disabled || option.status !== 'ready'"
              @click="emit('more', option)"
            >
              <Sparkles class="size-3.5" />
              {{ $t('More like this') }}
            </Button>
            <Button
              type="button"
              size="sm"
              class="flex-1"
              :disabled="disabled || option.status !== 'ready'"
              @click="emit('pin', option)"
            >
              <Check class="size-3.5" />
              {{ $t('Use this') }}
            </Button>
          </div>
        </div>
      </li>
    </ul>

    <Dialog v-model:open="previewOpen">
      <DialogContent class="max-w-4xl">
        <DialogHeader>
          <DialogTitle>{{ preview?.name }}</DialogTitle>
          <DialogDescription>{{ preview?.look }} {{ preview?.lighting }}</DialogDescription>
        </DialogHeader>
        <img v-if="preview?.imageUrl" :src="preview.imageUrl" :alt="preview.name" class="w-full rounded-lg" />
      </DialogContent>
    </Dialog>
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { Button } from '@shared:ui/button'
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@shared:ui/dialog'
import { Check, LoaderCircle, Sparkles } from 'lucide-vue-next'
import { ref } from 'vue'

import type { ChatMessage, StyleOptionTile } from './types'

defineProps<{
  message: Extract<ChatMessage, { kind: 'style-options' }>
  disabled?: boolean
}>()

const emit = defineEmits<{
  more: [option: StyleOptionTile]
  pin: [option: StyleOptionTile]
}>()

const preview = ref<StyleOptionTile | null>(null)
const previewOpen = ref(false)

const open = (option: StyleOptionTile) => {
  preview.value = option
  previewOpen.value = true
}
</script>
