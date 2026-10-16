import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Activities\IndexController::index
 * @see app/Http/Controllers/Admin/Activities/IndexController.php:16
 * @route '/admin/activities'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/admin/activities',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Activities\IndexController::index
 * @see app/Http/Controllers/Admin/Activities/IndexController.php:16
 * @route '/admin/activities'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Activities\IndexController::index
 * @see app/Http/Controllers/Admin/Activities/IndexController.php:16
 * @route '/admin/activities'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Activities\IndexController::index
 * @see app/Http/Controllers/Admin/Activities/IndexController.php:16
 * @route '/admin/activities'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

const IndexController = { index }

export default IndexController
