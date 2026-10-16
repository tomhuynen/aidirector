import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Tenants\IndexController::index
 * @see app/Http/Controllers/Admin/Tenants/IndexController.php:16
 * @route '/admin/tenants'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/admin/tenants',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\IndexController::index
 * @see app/Http/Controllers/Admin/Tenants/IndexController.php:16
 * @route '/admin/tenants'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\IndexController::index
 * @see app/Http/Controllers/Admin/Tenants/IndexController.php:16
 * @route '/admin/tenants'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\IndexController::index
 * @see app/Http/Controllers/Admin/Tenants/IndexController.php:16
 * @route '/admin/tenants'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

const IndexController = { index }

export default IndexController
