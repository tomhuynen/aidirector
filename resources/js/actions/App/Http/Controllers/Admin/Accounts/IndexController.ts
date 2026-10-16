import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Accounts\IndexController::index
 * @see app/Http/Controllers/Admin/Accounts/IndexController.php:16
 * @route '/admin/accounts'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/admin/accounts',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\IndexController::index
 * @see app/Http/Controllers/Admin/Accounts/IndexController.php:16
 * @route '/admin/accounts'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\IndexController::index
 * @see app/Http/Controllers/Admin/Accounts/IndexController.php:16
 * @route '/admin/accounts'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\IndexController::index
 * @see app/Http/Controllers/Admin/Accounts/IndexController.php:16
 * @route '/admin/accounts'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

const IndexController = { index }

export default IndexController
