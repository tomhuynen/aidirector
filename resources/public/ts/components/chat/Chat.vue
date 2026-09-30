<template>
  <section
    class="relative flex min-h-0 flex-col rounded-xl border border-border bg-card/60"
    :class="dragging ? 'border-signal ring-2 ring-signal/40' : ''"
    :aria-label="label"
    @dragenter.prevent="onDragEnter"
    @dragover.prevent
    @dragleave.prevent="onDragLeave"
    @drop.prevent="onDrop"
  >
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
          <div
            class="flex max-w-[85%] flex-col gap-2 rounded-2xl px-4 py-3 text-[15px] leading-relaxed"
            :class="
              message.role === 'user'
                ? 'rounded-br-md bg-signal-soft/70 text-foreground'
                : 'rounded-bl-md bg-background/70 text-foreground'
            "
          >
            <ul v-if="message.attachments?.length" class="flex flex-wrap gap-2" :aria-label="$t('Attachments')">
              <li v-for="attachment in message.attachments" :key="attachment.id">
                <img
                  v-if="attachment.previewUrl"
                  :src="attachment.previewUrl"
                  :alt="attachment.name"
                  class="size-20 rounded-lg object-cover"
                />
                <span v-else class="flex size-20 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <Paperclip class="size-5" />
                </span>
              </li>
            </ul>
            <p v-if="message.content" class="whitespace-pre-wrap">{{ message.content }}</p>
          </div>
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
      <ul v-if="uploads.pending.value.length" class="mb-3 flex flex-wrap gap-2" :aria-label="$t('Files to send')">
        <li
          v-for="upload in uploads.pending.value"
          :key="upload.key"
          class="group relative"
          :title="upload.error ?? upload.name"
        >
          <img
            v-if="upload.previewUrl"
            :src="upload.previewUrl"
            :alt="upload.name"
            class="size-16 rounded-lg object-cover"
            :class="{ 'opacity-50': upload.status !== 'ready', 'ring-2 ring-destructive': upload.status === 'failed' }"
          />
          <span
            v-else
            class="flex size-16 items-center justify-center rounded-lg bg-muted text-muted-foreground"
            :class="{ 'ring-2 ring-destructive': upload.status === 'failed' }"
          >
            <Paperclip class="size-5" />
          </span>
          <LoaderCircle
            v-if="upload.status === 'uploading'"
            class="absolute inset-0 m-auto size-5 animate-spin text-foreground"
            aria-hidden="true"
          />
          <button
            type="button"
            class="absolute -top-1.5 -right-1.5 flex size-5 items-center justify-center rounded-full bg-foreground text-background shadow"
            :aria-label="$t('Remove :name', { name: upload.name })"
            @click="uploads.remove(upload.key)"
          >
            <X class="size-3" />
          </button>
        </li>
      </ul>

      <InputGroup class="items-end bg-background">
        <InputGroupAddon v-if="uploadUrl" align="inline-start" class="pb-2">
          <DropdownMenu>
            <DropdownMenuTrigger as-child>
              <InputGroupButton
                type="button"
                size="icon-sm"
                variant="ghost"
                :disabled="disabled"
                :aria-label="$t('Add')"
              >
                <Plus class="size-4" />
              </InputGroupButton>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start" class="w-80">
              <DropdownMenuItem class="flex items-baseline gap-2" @select="pickFiles">
                <Paperclip class="size-4 self-center" />
                <span class="font-medium">{{ $t('Add photos and files') }}</span>
                <span class="text-xs text-muted-foreground">{{ $t('Upload from your computer') }}</span>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <input ref="fileInput" type="file" class="sr-only" multiple :accept="accept" tabindex="-1" @change="onPick" />
        </InputGroupAddon>
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
            :disabled="!canSubmit"
            :aria-label="$t('Send')"
          >
            <LoaderCircle v-if="busy" class="size-4 animate-spin" />
            <ArrowUp v-else class="size-4" />
          </InputGroupButton>
        </InputGroupAddon>
      </InputGroup>
      <p class="mt-2 text-xs text-muted-foreground">
        {{ hint ?? $t('Enter to send, Shift + Enter for a new line.') }}
      </p>
    </form>
  </section>
</template>
<script setup lang="ts">
import { useUploads } from '@public/ts/composables/useUploads'
import { $t } from '@public/ts/shared/i18n'
import { Avatar, AvatarFallback } from '@shared:ui/avatar'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@shared:ui/dropdown-menu'
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupTextarea } from '@shared:ui/input-group'
import { Skeleton } from '@shared:ui/skeleton'
import { ArrowUp, Clapperboard, LoaderCircle, Paperclip, Plus, X } from 'lucide-vue-next'
import { computed, nextTick, ref, watch } from 'vue'

import type { ChatAttachment, ChatMessage } from './types'

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
    /** Where files are staged. Without it the chat is text only. */
    uploadUrl?: string
    /** What the file picker accepts. */
    accept?: string
    /** Replaces the keyboard hint under the input, e.g. when the assistant asks for photos. */
    hint?: string | null
  }>(),
  {
    busy: false,
    error: null,
    placeholder: undefined,
    disabled: false,
    userInitial: '',
    label: undefined,
    uploadUrl: undefined,
    accept: 'image/*',
    hint: null,
  },
)

const emit = defineEmits<{
  send: [text: string, attachments: ChatAttachment[]]
}>()

defineSlots<{
  message(props: { message: ChatMessage }): unknown
}>()

const draft = ref('')
const thread = ref<HTMLElement | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const dragging = ref(false)
let dragDepth = 0

const uploads = useUploads({ url: () => props.uploadUrl ?? '' })

const canSubmit = computed(
  () =>
    !props.disabled &&
    !props.busy &&
    !uploads.busy.value &&
    (draft.value.trim() !== '' || uploads.ready.value.length > 0),
)

const submit = () => {
  if (!canSubmit.value) {
    return
  }

  const attachments = uploads.take().map(({ id, name, previewUrl }) => ({ id: id as string, name, previewUrl }))

  emit('send', draft.value.trim(), attachments)
  draft.value = ''
}

const pickFiles = () => fileInput.value?.click()

const addFiles = (files: FileList | null) => {
  if (!props.uploadUrl || props.disabled || !files?.length) {
    return
  }

  void uploads.add(files)
}

const onPick = (event: Event) => {
  const input = event.target as HTMLInputElement

  addFiles(input.files)
  input.value = ''
}

const onDragEnter = () => {
  dragDepth += 1
  dragging.value = Boolean(props.uploadUrl) && !props.disabled
}

const onDragLeave = () => {
  dragDepth = Math.max(0, dragDepth - 1)
  dragging.value = dragDepth > 0 && dragging.value
}

const onDrop = (event: DragEvent) => {
  dragDepth = 0
  dragging.value = false
  addFiles(event.dataTransfer?.files ?? null)
}

const scrollToEnd = async () => {
  await nextTick()
  thread.value?.scrollTo({ top: thread.value.scrollHeight, behavior: 'smooth' })
}

watch(() => [props.messages.length, props.busy, props.error], scrollToEnd, { immediate: true })
</script>
