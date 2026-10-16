import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Dashboard\IndexController::index
 * @see app/Http/Controllers/Admin/Dashboard/IndexController.php:12
 * @route '/admin'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/admin',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Dashboard\IndexController::index
 * @see app/Http/Controllers/Admin/Dashboard/IndexController.php:12
 * @route '/admin'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Dashboard\IndexController::index
 * @see app/Http/Controllers/Admin/Dashboard/IndexController.php:12
 * @route '/admin'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Dashboard\IndexController::index
 * @see app/Http/Controllers/Admin/Dashboard/IndexController.php:12
 * @route '/admin'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

const dashboard = {
  index: Object.assign(index, index),
}

export default dashboard
