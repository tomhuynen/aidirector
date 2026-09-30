<template>
  <Head :title="$t('New project')" />

  <!-- Absolutely positioned inside <main>, so the chat is exactly as tall as the content area and only its thread scrolls. -->
  <div class="absolute inset-x-6 inset-y-12 flex flex-col gap-4 md:inset-y-16">
    <Chat
      class="min-h-0 flex-1"
      :messages="messages"
      :busy="busy || styleActivity !== null"
      :busy-label="styleActivity"
      :error="error"
      :disabled="done"
      :user-initial="userInitial"
      :label="$t('Project setup')"
      :upload-url="uploadUrl"
      :hint="hint"
      @send="send"
    >
      <template #style-options="{ message }">
        <StyleOptionsGrid :message="message" :disabled="done || styleActivity !== null" @more="moreLike" @pin="pin" />
      </template>
    </Chat>

    <p v-if="done" class="flex items-center gap-2 text-sm text-muted-foreground">
      <LoaderCircle class="size-4 animate-spin" />
      {{ $t('Project ready. Opening it…') }}
    </p>
  </div>
</template>
<script setup lang="ts">
import { Head, router, useHttp } from '@inertiajs/vue3'
import { usePage } from '@public/ts/composables/page'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia, PostResponse } from '@public/ts/types/utils'
import Chat from '@public:components/chat/Chat.vue'
import StyleOptionsGrid from '@public:components/chat/StyleOptionsGrid.vue'
import type { ChatMessage, NewChatMessage, StyleOptionTile } from '@public:components/chat/types'
import { useChat } from '@public:components/chat/useChat'
import { LoaderCircle } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'

defineOptions({
  layout: AppLayout,
})

// The setup route renders this page too; its props are the superset (resume may be null).
const props = defineProps<Inertia.Pages.Projects.Setup>()

type ChatTurn = PostResponse<'/projects/create/chat'>
type StyleRound = PostResponse<'/projects/{project}/style/rounds'>
type StyleOptionsMessage = Extract<ChatMessage, { kind: 'style-options' }>

const { account } = usePage()
const userInitial = computed(() => account.value?.name.trim().charAt(0).toUpperCase() ?? '')

const done = ref(false)
const ask = ref<ChatTurn['ask']>(props.resume?.ask ?? null)
const project = ref<ChatTurn['project']>(props.resume?.project ?? null)
/** What the style exploration is doing right now, shown in the chat; null when idle. */
const styleActivity = ref<string | null>(null)

const hint = computed(() => {
  switch (ask.value) {
    case 'photos':
      return $t('Add photos with the + button, or say you have none.')
    case 'style':
      return $t('Pick the style that comes closest, or ask for more like one of them.')
    default:
      return null
  }
})

const http = useHttp<{ conversation: string | null; message: string; uploads: string[] }, ChatTurn>({
  conversation: props.resume?.conversation ?? null,
  message: '',
  uploads: [],
})

const unavailable = () => $t('The director is unavailable right now. Please try again.')

const messageFrom = (caught: unknown): string => {
  const response = (caught as { response?: { data?: string } })?.response

  try {
    const data = response?.data ? (JSON.parse(response.data) as { message?: string }) : null

    return data?.message ?? unavailable()
  } catch {
    return unavailable()
  }
}

const { messages, busy, error, send, push } = useChat({
  // A resumed thread is rebuilt on the server in the same shape the chat keeps.
  initial: props.resume
    ? (props.resume.messages as unknown as NewChatMessage[])
    : [{ kind: 'text', role: 'assistant', content: props.greeting }],
  send: async (text, attachments) => {
    http.message = text
    http.uploads = attachments.map((attachment) => attachment.id)

    let turn: ChatTurn

    try {
      turn = await http.post(props.chatUrl)
    } catch (caught) {
      throw new Error(http.errors.message ?? http.errors.uploads ?? messageFrom(caught))
    }

    http.conversation = turn.conversation
    ask.value = turn.ask
    project.value = turn.project

    if (turn.done && turn.project) {
      done.value = true
      router.visit(turn.project.url)
    } else if (turn.ask === 'style' && !hasStyleRound.value) {
      void startRound()
    }

    return turn.reply
  },
})

/*
 * Style exploration: a round is a grid message in the thread that polls its
 * options while they render. "More like this" starts a round from that
 * option; "Use this" pins it and reports the choice back to the director.
 */
const hasStyleRound = computed(() => messages.value.some((message) => message.kind === 'style-options'))

const rounds = useHttp<{ parent: string | null }, StyleRound>({ parent: null })
const pinning = useHttp<Record<string, never>, { option: StyleOptionTile }>({})
const polls = new Set<ReturnType<typeof setInterval>>()

const poll = (message: StyleOptionsMessage) => {
  const timer = setInterval(async () => {
    try {
      const response = await fetch(message.optionsUrl, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      })

      if (!response.ok) {
        return
      }

      message.options = (await response.json()) as StyleOptionTile[]

      if (message.options.every((option) => option.status !== 'pending')) {
        clearInterval(timer)
        polls.delete(timer)
      }
    } catch {
      // Keep polling; a transient failure should not end the round.
    }
  }, 2500)

  polls.add(timer)
}

const startRound = async (parent: StyleOptionTile | null = null) => {
  if (!project.value || styleActivity.value !== null) {
    return
  }

  styleActivity.value = parent
    ? $t('Preparing more like “:name”…', { name: parent.name })
    : $t('Preparing style directions…')
  error.value = null
  rounds.parent = parent?.id ?? null

  try {
    const options = await rounds.post(project.value.styleRoundsUrl)
    const round = options[0]?.round ?? 1

    const message = push({
      kind: 'style-options',
      role: 'assistant',
      content: parent
        ? $t('More like “:name”.', { name: parent.name })
        : $t('Four directions, each rendered with your own subjects.'),
      round,
      optionsUrl: `${project.value.styleRoundsUrl}/${round}`,
      options,
    }) as StyleOptionsMessage

    poll(message)
  } catch (caught) {
    error.value = rounds.errors.parent ?? messageFrom(caught)
  } finally {
    styleActivity.value = null
  }
}

const moreLike = (option: StyleOptionTile) => startRound(option)

const pin = async (option: StyleOptionTile) => {
  if (styleActivity.value !== null) {
    return
  }

  styleActivity.value = $t('Saving your style…')

  try {
    const result = await pinning.post(option.links.pin)

    for (const message of messages.value) {
      if (message.kind === 'style-options') {
        message.options = message.options.map((tile) => ({ ...tile, pinned: tile.id === result.option.id }))
      }
    }

    // Hand over to the chat's own typing indicator while the director replies.
    styleActivity.value = null
    await send($t('Style chosen: :name. :look', { name: option.name, look: option.look }))
  } catch (caught) {
    error.value = messageFrom(caught)
  } finally {
    styleActivity.value = null
  }
}

/*
 * On resume, keep polling rounds that were still rendering, and start the
 * first round if the director was asked for a style but none exists yet.
 */
onMounted(() => {
  for (const message of messages.value) {
    if (message.kind === 'style-options' && message.options.some((option) => option.status === 'pending')) {
      poll(message)
    }
  }

  if (ask.value === 'style' && !hasStyleRound.value) {
    void startRound()
  }
})

onBeforeUnmount(() => polls.forEach((timer) => clearInterval(timer)))
</script>
