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

/** One photo found by search, as the chat API returns it. */
export type PhotoSuggestionTile = NonNullable<PostResponse<'/projects/create/chat'>['gallery']>['suggestions'][number]

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
    | {
        kind: 'photo-gallery'
        batch: number
        /** Where the ticked photos are sent to be downloaded. */
        pickUrl: string
        suggestions: PhotoSuggestionTile[]
      }
  )

export type ChatMessageKind = ChatMessage['kind']

export type NewChatMessage = ChatMessage extends infer M ? (M extends ChatMessage ? Omit<M, 'id'> : never) : never
