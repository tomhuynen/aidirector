import type { GetResponse, PostResponse } from '@public/ts/types/utils'

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
 * A cast and sets round as the API returns it: its status and the
 * suggestions with their renders so far. The polling endpoint returns the
 * same shape.
 */
export type ElementRoundState = NonNullable<PostResponse<'/projects/create/chat'>['elementRound']>

export type ElementOptionTile = ElementRoundState['options'][number]

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
    | ({ kind: 'element-options' } & ElementRoundState)
  )

export type ChatMessageKind = ChatMessage['kind']

export type NewChatMessage = ChatMessage extends infer M ? (M extends ChatMessage ? Omit<M, 'id'> : never) : never
