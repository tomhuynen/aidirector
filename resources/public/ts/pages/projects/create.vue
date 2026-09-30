<template>
  <Page
    :eyebrow="$t('New project')"
    :title="$t('Let’s set up your project')"
    :description="
      $t('Your director asks what it needs to know: what the project is about, who it is for, and what to call it.')
    "
  >
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
      <Chat
        class="h-[60vh] min-h-[28rem]"
        :messages="messages"
        :busy="busy"
        :error="error"
        :disabled="done"
        :user-initial="userInitial"
        :label="$t('Project setup')"
        :upload-url="uploadUrl"
        :hint="hint"
        @send="send"
      />

      <div class="flex items-center justify-between gap-3">
        <p v-if="done" class="flex items-center gap-2 text-sm text-muted-foreground">
          <LoaderCircle class="size-4 animate-spin" />
          {{ $t('Project ready. Opening it…') }}
        </p>
        <span v-else />
        <Button as-child variant="ghost">
          <Link :href="index.url()">{{ $t('Cancel') }}</Link>
        </Button>
      </div>
    </div>
  </Page>
</template>
<script setup lang="ts">
import { Link, router, useHttp } from '@inertiajs/vue3'
import { usePage } from '@public/ts/composables/page'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia, PostResponse } from '@public/ts/types/utils'
import Chat from '@public:components/chat/Chat.vue'
import { useChat } from '@public:components/chat/useChat'
import Page from '@public:components/Page.vue'
import { index } from '@routes/public/projects'
import { Button } from '@shared:ui/button'
import { LoaderCircle } from 'lucide-vue-next'
import { computed, ref } from 'vue'

defineOptions({
  layout: AppLayout,
})

const props = defineProps<Inertia.Pages.Projects.Create>()

type ChatTurn = PostResponse<'/projects/create/chat'>

const { account } = usePage()
const userInitial = computed(() => account.value?.name.trim().charAt(0).toUpperCase() ?? '')

const done = ref(false)
const ask = ref<ChatTurn['ask']>(null)

const hint = computed(() => (ask.value === 'photos' ? $t('Add photos with the + button, or say you have none.') : null))

const http = useHttp<{ conversation: string | null; message: string; uploads: string[] }, ChatTurn>({
  conversation: null,
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

const { messages, busy, error, send } = useChat({
  initial: [{ role: 'assistant', content: props.greeting }],
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

    if (turn.done && turn.project) {
      done.value = true
      router.visit(turn.project.url)
    }

    return turn.reply
  },
})
</script>
