import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
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

/**
 * @see \App\Support\Search\Http\Controllers\Search\ViewController::view
 * @see app/Support/Search/Http/Controllers/Search/ViewController.php:22
 * @route '/admin/search/{entity}/{id}'
 */
export const view = (
  args: { entity: string | number; id: string | number } | [entity: string | number, id: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/admin/search/{entity}/{id}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Support\Search\Http\Controllers\Search\ViewController::view
 * @see app/Support/Search/Http/Controllers/Search/ViewController.php:22
 * @route '/admin/search/{entity}/{id}'
 */
view.url = (
  args: { entity: string | number; id: string | number } | [entity: string | number, id: string | number],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      entity: args[0],
      id: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    entity: args.entity,
    id: args.id,
  }

  return (
    view.definition.url
      .replace('{entity}', parsedArgs.entity.toString())
      .replace('{id}', parsedArgs.id.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Support\Search\Http\Controllers\Search\ViewController::view
 * @see app/Support/Search/Http/Controllers/Search/ViewController.php:22
 * @route '/admin/search/{entity}/{id}'
 */
view.get = (
  args: { entity: string | number; id: string | number } | [entity: string | number, id: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Support\Search\Http\Controllers\Search\ViewController::view
 * @see app/Support/Search/Http/Controllers/Search/ViewController.php:22
 * @route '/admin/search/{entity}/{id}'
 */
view.head = (
  args: { entity: string | number; id: string | number } | [entity: string | number, id: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

const search = {
  index: Object.assign(index, index),
  view: Object.assign(view, view),
}

export default search
