import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Auth\ResetPasswordController::store
 * @see app/Http/Controllers/Public/Auth/ResetPasswordController.php:29
 * @route '/reset-password'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/reset-password',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Auth\ResetPasswordController::store
 * @see app/Http/Controllers/Public/Auth/ResetPasswordController.php:29
 * @route '/reset-password'
 */
store.url = (options?: RouteQueryOptions) => {
  return store.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\ResetPasswordController::store
 * @see app/Http/Controllers/Public/Auth/ResetPasswordController.php:29
 * @route '/reset-password'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

const resetPassword = {
  store: Object.assign(store, store),
}

export default resetPassword
