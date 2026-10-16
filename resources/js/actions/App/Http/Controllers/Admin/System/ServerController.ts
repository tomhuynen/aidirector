import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\System\ServerController::store
 * @see app/Http/Controllers/Admin/System/ServerController.php:33
 * @route '/admin/system/server'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/admin/system/server',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Admin\System\ServerController::store
 * @see app/Http/Controllers/Admin/System/ServerController.php:33
 * @route '/admin/system/server'
 */
store.url = (options?: RouteQueryOptions) => {
  return store.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\System\ServerController::store
 * @see app/Http/Controllers/Admin/System/ServerController.php:33
 * @route '/admin/system/server'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Admin\System\ServerController::index
 * @see app/Http/Controllers/Admin/System/ServerController.php:21
 * @route '/admin/system/server'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/admin/system/server',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\System\ServerController::index
 * @see app/Http/Controllers/Admin/System/ServerController.php:21
 * @route '/admin/system/server'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\System\ServerController::index
 * @see app/Http/Controllers/Admin/System/ServerController.php:21
 * @route '/admin/system/server'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\System\ServerController::index
 * @see app/Http/Controllers/Admin/System/ServerController.php:21
 * @route '/admin/system/server'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

const ServerController = { store, index }

export default ServerController
