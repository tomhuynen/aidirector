import { ref } from 'vue'

import type { ChatAttachment, ChatMessage, ChatRole, NewChatMessage } from './types'

type UseChatOptions = {
  /** Messages shown before the user says anything: a greeting, or a resumed thread. */
  initial?: NewChatMessage[]
  /** Sends the user's text and attachments and resolves with the assistant's reply. */
  send: (text: string, attachments: ChatAttachment[]) => Promise<string | null>
}

let nextId = 0

const makeMessage = (message: NewChatMessage): ChatMessage => ({ ...message, id: `chat-${++nextId}` }) as ChatMessage

/**
 * Transport-agnostic chat state: keeps the thread, appends the user's message
 * before the request goes out, and appends the reply when it comes back.
 */
export function useChat(options: UseChatOptions) {
  const messages = ref<ChatMessage[]>(
    (options.initial ?? []).map((message) =>
      makeMessage({ kind: 'text', role: message.role, content: message.content }),
    ),
  )
  const busy = ref(false)
  const error = ref<string | null>(null)

  /** Appends any message kind and returns the reactive copy in the thread. */
  const push = (message: NewChatMessage): ChatMessage => {
    messages.value.push(makeMessage(message))

    return messages.value[messages.value.length - 1]
  }

  const pushText = (role: ChatRole, content: string, attachments: ChatAttachment[] = []) =>
    push({ kind: 'text', role, content, attachments })

  const send = async (text: string, attachments: ChatAttachment[] = []) => {
    const content = text.trim()

    if ((content === '' && attachments.length === 0) || busy.value) {
      return
    }

    error.value = null
    busy.value = true
    pushText('user', content, attachments)

    try {
      const reply = await options.send(content, attachments)

      if (reply) {
        pushText('assistant', reply)
      }
    } catch (caught) {
      error.value = caught instanceof Error ? caught.message : String(caught)
    } finally {
      busy.value = false
    }
  }

  return { messages, busy, error, send, push, pushText }
}
