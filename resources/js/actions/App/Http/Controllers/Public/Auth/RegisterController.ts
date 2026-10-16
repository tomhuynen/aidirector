import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Auth\RegisterController::view
 * @see app/Http/Controllers/Public/Auth/RegisterController.php:17
 * @route '/register'
 */
export const view = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/register',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Auth\RegisterController::view
 * @see app/Http/Controllers/Public/Auth/RegisterController.php:17
 * @route '/register'
 */
view.url = (options?: RouteQueryOptions) => {
  return view.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\RegisterController::view
 * @see app/Http/Controllers/Public/Auth/RegisterController.php:17
 * @route '/register'
 */
view.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Auth\RegisterController::view
 * @see app/Http/Controllers/Public/Auth/RegisterController.php:17
 * @route '/register'
 */
view.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: view.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Auth\RegisterController::store
 * @see app/Http/Controllers/Public/Auth/RegisterController.php:25
 * @route '/register'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/register',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Auth\RegisterController::store
 * @see app/Http/Controllers/Public/Auth/RegisterController.php:25
 * @route '/register'
 */
store.url = (options?: RouteQueryOptions) => {
  return store.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\RegisterController::store
 * @see app/Http/Controllers/Public/Auth/RegisterController.php:25
 * @route '/register'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

const RegisterController = { view, store }

export default RegisterController
