import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::view
 * @see app/Http/Controllers/Public/Auth/LoginController.php:15
 * @route '/login'
 */
export const view = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/login',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::view
 * @see app/Http/Controllers/Public/Auth/LoginController.php:15
 * @route '/login'
 */
view.url = (options?: RouteQueryOptions) => {
  return view.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::view
 * @see app/Http/Controllers/Public/Auth/LoginController.php:15
 * @route '/login'
 */
view.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::view
 * @see app/Http/Controllers/Public/Auth/LoginController.php:15
 * @route '/login'
 */
view.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: view.url(options),
  method: 'head',
})

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

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::destroy
 * @see app/Http/Controllers/Public/Auth/LoginController.php:37
 * @route '/logout'
 */
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: destroy.url(options),
  method: 'post',
})

destroy.definition = {
  methods: ['post'],
  url: '/logout',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::destroy
 * @see app/Http/Controllers/Public/Auth/LoginController.php:37
 * @route '/logout'
 */
destroy.url = (options?: RouteQueryOptions) => {
  return destroy.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::destroy
 * @see app/Http/Controllers/Public/Auth/LoginController.php:37
 * @route '/logout'
 */
destroy.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: destroy.url(options),
  method: 'post',
})

const LoginController = { view, store, destroy }

export default LoginController
