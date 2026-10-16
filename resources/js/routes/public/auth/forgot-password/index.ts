import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Auth\ForgotPasswordController::store
 * @see app/Http/Controllers/Public/Auth/ForgotPasswordController.php:18
 * @route '/forgot-password'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/forgot-password',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Auth\ForgotPasswordController::store
 * @see app/Http/Controllers/Public/Auth/ForgotPasswordController.php:18
 * @route '/forgot-password'
 */
store.url = (options?: RouteQueryOptions) => {
  return store.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\ForgotPasswordController::store
 * @see app/Http/Controllers/Public/Auth/ForgotPasswordController.php:18
 * @route '/forgot-password'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

const forgotPassword = {
  store: Object.assign(store, store),
}

export default forgotPassword
