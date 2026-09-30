<template>
  <section class="flex min-h-0 flex-col rounded-xl border border-border bg-card/60" :aria-label="label">
    <div ref="thread" class="min-h-0 flex-1 space-y-5 overflow-y-auto px-5 py-6 sm:px-6" aria-live="polite">
      <div
        v-for="message in messages"
        :key="message.id"
        class="flex items-end gap-3"
        :class="message.role === 'user' ? 'flex-row-reverse' : 'flex-row'"
      >
        <Avatar class="size-8 shrink-0">
          <AvatarFallback
            class="text-xs font-semibold"
            :class="message.role === 'assistant' ? 'bg-signal-soft text-signal' : 'bg-muted text-foreground'"
          >
            <Clapperboard v-if="message.role === 'assistant'" class="size-4" />
            <template v-else>{{ userInitial }}</template>
          </AvatarFallback>
        </Avatar>

        <slot name="message" :message="message">
          <p
            class="max-w-[85%] rounded-2xl px-4 py-3 text-[15px] leading-relaxed whitespace-pre-wrap"
            :class="
              message.role === 'user'
                ? 'rounded-br-md bg-signal-soft/70 text-foreground'
                : 'rounded-bl-md bg-background/70 text-foreground'
            "
          >
            {{ message.content }}
          </p>
        </slot>
      </div>

      <div v-if="busy" class="flex items-end gap-3" :aria-label="$t('The director is typing')">
        <Avatar class="size-8 shrink-0">
          <AvatarFallback class="bg-signal-soft text-signal"><Clapperboard class="size-4" /></AvatarFallback>
        </Avatar>
        <div class="flex items-center gap-1.5 rounded-2xl rounded-bl-md bg-background/70 px-4 py-4">
          <Skeleton class="size-2 rounded-full bg-signal/60" />
          <Skeleton class="size-2 rounded-full bg-signal/60 [animation-delay:150ms]" />
          <Skeleton class="size-2 rounded-full bg-signal/60 [animation-delay:300ms]" />
        </div>
      </div>

      <p v-if="error" class="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm">
        {{ error }}
      </p>
    </div>

    <form class="shrink-0 px-5 pb-5 sm:px-6" @submit.prevent="submit">
      <InputGroup class="items-end bg-background">
        <InputGroupTextarea
          v-model="draft"
          rows="1"
          maxlength="2000"
          :placeholder="placeholder ?? $t('Type your answer…')"
          :disabled="disabled"
          :aria-label="$t('Your message')"
          class="max-h-40 min-h-12 px-4 text-[15px] leading-relaxed"
          @keydown.enter.exact.prevent="submit"
        />
        <InputGroupAddon align="inline-end" class="pb-2">
          <InputGroupButton
            type="submit"
            size="icon-sm"
            variant="default"
            :disabled="disabled || busy || draft.trim() === ''"
            :aria-label="$t('Send')"
          >
            <LoaderCircle v-if="busy" class="size-4 animate-spin" />
            <ArrowUp v-else class="size-4" />
          </InputGroupButton>
        </InputGroupAddon>
      </InputGroup>
      <p class="mt-2 text-xs text-muted-foreground">{{ $t('Enter to send, Shift + Enter for a new line.') }}</p>
    </form>
  </section>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { Avatar, AvatarFallback } from '@shared:ui/avatar'
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupTextarea } from '@shared:ui/input-group'
import { Skeleton } from '@shared:ui/skeleton'
import { ArrowUp, Clapperboard, LoaderCircle } from 'lucide-vue-next'
import { nextTick, ref, watch } from 'vue'

import type { ChatMessage } from './types'

const props = withDefaults(
  defineProps<{
    messages: ChatMessage[]
    busy?: boolean
    error?: string | null
    placeholder?: string
    disabled?: boolean
    /** Shown in the user's avatar. */
    userInitial?: string
    label?: string
  }>(),
  {
    busy: false,
    error: null,
    placeholder: undefined,
    disabled: false,
    userInitial: '',
    label: undefined,
  },
)

const emit = defineEmits<{
  send: [text: string]
}>()

defineSlots<{
  message(props: { message: ChatMessage }): unknown
}>()

const draft = ref('')
const thread = ref<HTMLElement | null>(null)

const submit = () => {
  const text = draft.value.trim()

  if (text === '' || props.busy || props.disabled) {
    return
  }

  emit('send', text)
  draft.value = ''
}

const scrollToEnd = async () => {
  await nextTick()
  thread.value?.scrollTo({ top: thread.value.scrollHeight, behavior: 'smooth' })
}

watch(() => [props.messages.length, props.busy, props.error], scrollToEnd, { immediate: true })
</script>
