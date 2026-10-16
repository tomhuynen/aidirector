import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::store
 * @see app/Http/Controllers/Public/Auth/LoginController.php:22
 * @route '/login'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/login',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::store
 * @see app/Http/Controllers/Public/Auth/LoginController.php:22
 * @route '/login'
 */
store.url = (options?: RouteQueryOptions) => {
  return store.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::store
 * @see app/Http/Controllers/Public/Auth/LoginController.php:22
 * @route '/login'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

const login = {
  store: Object.assign(store, store),
}

export default login
