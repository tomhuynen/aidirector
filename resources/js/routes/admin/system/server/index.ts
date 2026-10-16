import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
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
