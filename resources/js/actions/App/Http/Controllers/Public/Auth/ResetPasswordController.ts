import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Auth\ResetPasswordController::view
 * @see app/Http/Controllers/Public/Auth/ResetPasswordController.php:19
 * @route '/reset-password/{token}'
 */
export const view = (
  args: { token: string | number } | [token: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/reset-password/{token}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Auth\ResetPasswordController::view
 * @see app/Http/Controllers/Public/Auth/ResetPasswordController.php:19
 * @route '/reset-password/{token}'
 */
view.url = (
  args: { token: string | number } | [token: string | number] | string | number,
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { token: args }
  }

  if (Array.isArray(args)) {
    args = {
      token: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    token: args.token,
  }

  return view.definition.url.replace('{token}', parsedArgs.token.toString()).replace(/\/+$/, '') + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\ResetPasswordController::view
 * @see app/Http/Controllers/Public/Auth/ResetPasswordController.php:19
 * @route '/reset-password/{token}'
 */
view.get = (
  args: { token: string | number } | [token: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Auth\ResetPasswordController::view
 * @see app/Http/Controllers/Public/Auth/ResetPasswordController.php:19
 * @route '/reset-password/{token}'
 */
view.head = (
  args: { token: string | number } | [token: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

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

const ResetPasswordController = { view, store }

export default ResetPasswordController
