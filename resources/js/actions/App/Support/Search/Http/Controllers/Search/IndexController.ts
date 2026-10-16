import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Support\Search\Http\Controllers\Search\IndexController::index
 * @see app/Support/Search/Http/Controllers/Search/IndexController.php:24
 * @route '/admin/search/{entity}'
 */
export const index = (
  args: { entity: string | number } | [entity: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: index.url(args, options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/admin/search/{entity}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Support\Search\Http\Controllers\Search\IndexController::index
 * @see app/Support/Search/Http/Controllers/Search/IndexController.php:24
 * @route '/admin/search/{entity}'
 */
index.url = (
  args: { entity: string | number } | [entity: string | number] | string | number,
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { entity: args }
  }

  if (Array.isArray(args)) {
    args = {
      entity: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    entity: args.entity,
  }

  return (
    index.definition.url.replace('{entity}', parsedArgs.entity.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Support\Search\Http\Controllers\Search\IndexController::index
 * @see app/Support/Search/Http/Controllers/Search/IndexController.php:24
 * @route '/admin/search/{entity}'
 */
index.get = (
  args: { entity: string | number } | [entity: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: index.url(args, options),
  method: 'get',
})

/**
 * @see \App\Support\Search\Http\Controllers\Search\IndexController::index
 * @see app/Support/Search/Http/Controllers/Search/IndexController.php:24
 * @route '/admin/search/{entity}'
 */
index.head = (
  args: { entity: string | number } | [entity: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: index.url(args, options),
  method: 'head',
})

const IndexController = { index }

export default IndexController
