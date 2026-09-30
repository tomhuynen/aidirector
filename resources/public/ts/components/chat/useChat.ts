import { ref } from 'vue'

import type { ChatAttachment, ChatMessage, ChatRole } from './types'

type UseChatOptions = {
  /** Messages shown before the user says anything, such as a greeting. */
  initial?: Omit<ChatMessage, 'id' | 'kind'>[]
  /** Sends the user's text and attachments and resolves with the assistant's reply. */
  send: (text: string, attachments: ChatAttachment[]) => Promise<string | null>
}

let nextId = 0

const makeMessage = (role: ChatRole, content: string, attachments: ChatAttachment[] = []): ChatMessage => ({
  id: `chat-${++nextId}`,
  role,
  kind: 'text',
  content,
  attachments,
})

/**
 * Transport-agnostic chat state: keeps the thread, appends the user's message
 * before the request goes out, and appends the reply when it comes back.
 */
export function useChat(options: UseChatOptions) {
  const messages = ref<ChatMessage[]>(
    (options.initial ?? []).map((message) => makeMessage(message.role, message.content, message.attachments)),
  )
  const busy = ref(false)
  const error = ref<string | null>(null)

  const push = (role: ChatRole, content: string, attachments: ChatAttachment[] = []) => {
    const message = makeMessage(role, content, attachments)
    messages.value.push(message)

    return message
  }

  const send = async (text: string, attachments: ChatAttachment[] = []) => {
    const content = text.trim()

    if ((content === '' && attachments.length === 0) || busy.value) {
      return
    }

    error.value = null
    busy.value = true
    push('user', content, attachments)

    try {
      const reply = await options.send(content, attachments)

      if (reply) {
        push('assistant', reply)
      }
    } catch (caught) {
      error.value = caught instanceof Error ? caught.message : String(caught)
    } finally {
      busy.value = false
    }
  }

  return { messages, busy, error, send, push }
}
