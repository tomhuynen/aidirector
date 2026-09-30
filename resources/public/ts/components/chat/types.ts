export type ChatRole = 'user' | 'assistant'

/**
 * `kind` is the extension point for later message types (generated images,
 * style pickers). Every kind carries a plain-text `content` as fallback.
 */
export type ChatMessageKind = 'text'

export type ChatMessage = {
  id: string
  role: ChatRole
  kind: ChatMessageKind
  content: string
}
