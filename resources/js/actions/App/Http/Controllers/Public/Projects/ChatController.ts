import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\ChatController::store
 * @see app/Http/Controllers/Public/Projects/ChatController.php:57
 * @route '/projects/create/chat'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/projects/create/chat',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\ChatController::store
 * @see app/Http/Controllers/Public/Projects/ChatController.php:57
 * @route '/projects/create/chat'
 */
store.url = (options?: RouteQueryOptions) => {
  return store.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Projects\ChatController::store
 * @see app/Http/Controllers/Public/Projects/ChatController.php:57
 * @route '/projects/create/chat'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

const ChatController = { store }

export default ChatController
