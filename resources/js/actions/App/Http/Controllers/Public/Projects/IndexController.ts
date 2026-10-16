import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\IndexController::index
 * @see app/Http/Controllers/Public/Projects/IndexController.php:16
 * @route '/projects'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/projects',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\IndexController::index
 * @see app/Http/Controllers/Public/Projects/IndexController.php:16
 * @route '/projects'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Projects\IndexController::index
 * @see app/Http/Controllers/Public/Projects/IndexController.php:16
 * @route '/projects'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\IndexController::index
 * @see app/Http/Controllers/Public/Projects/IndexController.php:16
 * @route '/projects'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

const IndexController = { index }

export default IndexController
