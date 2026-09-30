<template>
  <Page
    :eyebrow="$t('New project')"
    :title="$t('Let’s set up your project')"
    :description="$t('Your director asks what it needs to know. For now, just the title.')"
  >
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
      <Chat
        class="h-[60vh] min-h-[28rem]"
        :messages="messages"
        :busy="busy"
        :error="error"
        :disabled="created"
        :user-initial="userInitial"
        :label="$t('Project setup')"
        @send="send"
      />

      <div class="flex items-center justify-between gap-3">
        <p v-if="created" class="flex items-center gap-2 text-sm text-muted-foreground">
          <LoaderCircle class="size-4 animate-spin" />
          {{ $t('Project created. Opening it…') }}
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

const created = ref(false)

const http = useHttp<{ conversation: string | null; message: string }, ChatTurn>({
  conversation: null,
  message: '',
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
  send: async (text) => {
    http.message = text

    let turn: ChatTurn

    try {
      turn = await http.post(props.chatUrl)
    } catch (caught) {
      throw new Error(http.errors.message ?? messageFrom(caught))
    }

    http.conversation = turn.conversation

    if (turn.project) {
      created.value = true
      router.visit(turn.project.url)
    }

    return turn.reply
  },
})
</script>
