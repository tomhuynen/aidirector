import type { GetResponse } from '@public/ts/types/utils'

export type ChatRole = 'user' | 'assistant'

export type ChatAttachment = {
  /** The staging upload's id, what the server claims. */
  id: string
  name: string
  previewUrl: string | null
}

type BaseMessage = {
  id: string
  role: ChatRole
  content: string
  attachments?: ChatAttachment[]
}

/** One tile of a style round, as the API returns it. */
export type StyleOptionTile = GetResponse<'/projects/{project}/style/rounds/{round}'>[number]

/**
 * `kind` is the extension point for message types beyond text. Every kind
 * carries a plain-text `content` as fallback.
 */
export type ChatMessage = BaseMessage &
  (
    | { kind: 'text' }
    | {
        kind: 'style-options'
        round: number
        /** Where the round's options are polled from while they render. */
        optionsUrl: string
        options: StyleOptionTile[]
      }
  )

export type ChatMessageKind = ChatMessage['kind']

export type NewChatMessage = ChatMessage extends infer M ? (M extends ChatMessage ? Omit<M, 'id'> : never) : never
