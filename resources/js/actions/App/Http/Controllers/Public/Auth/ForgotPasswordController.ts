import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Auth\ForgotPasswordController::view
 * @see app/Http/Controllers/Public/Auth/ForgotPasswordController.php:13
 * @route '/forgot-password'
 */
export const view = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/forgot-password',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Auth\ForgotPasswordController::view
 * @see app/Http/Controllers/Public/Auth/ForgotPasswordController.php:13
 * @route '/forgot-password'
 */
view.url = (options?: RouteQueryOptions) => {
  return view.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\ForgotPasswordController::view
 * @see app/Http/Controllers/Public/Auth/ForgotPasswordController.php:13
 * @route '/forgot-password'
 */
view.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Auth\ForgotPasswordController::view
 * @see app/Http/Controllers/Public/Auth/ForgotPasswordController.php:13
 * @route '/forgot-password'
 */
view.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: view.url(options),
  method: 'head',
})

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

const ForgotPasswordController = { view, store }

export default ForgotPasswordController
