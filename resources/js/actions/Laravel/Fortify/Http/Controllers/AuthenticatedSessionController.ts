import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../wayfinder'
/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::create
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
 * @route '/auth/login'
 */
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: create.url(options),
  method: 'get',
})

create.definition = {
  methods: ['get', 'head'],
  url: '/auth/login',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::create
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
 * @route '/auth/login'
 */
create.url = (options?: RouteQueryOptions) => {
  return create.definition.url + queryParams(options)
}

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::create
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
 * @route '/auth/login'
 */
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: create.url(options),
  method: 'get',
})

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::create
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
 * @route '/auth/login'
 */
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: create.url(options),
  method: 'head',
})

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::store
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:58
 * @route '/auth/login'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/auth/login',
} satisfies RouteDefinition<['post']>

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::store
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:58
 * @route '/auth/login'
 */
store.url = (options?: RouteQueryOptions) => {
  return store.definition.url + queryParams(options)
}

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::store
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:58
 * @route '/auth/login'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::destroy
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
 * @route '/auth/logout'
 */
export const destroy = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: destroy.url(options),
  method: 'post',
})

destroy.definition = {
  methods: ['post'],
  url: '/auth/logout',
} satisfies RouteDefinition<['post']>

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::destroy
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
 * @route '/auth/logout'
 */
destroy.url = (options?: RouteQueryOptions) => {
  return destroy.definition.url + queryParams(options)
}

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::destroy
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
 * @route '/auth/logout'
 */
destroy.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: destroy.url(options),
  method: 'post',
})

const AuthenticatedSessionController = { create, store, destroy }

export default AuthenticatedSessionController
