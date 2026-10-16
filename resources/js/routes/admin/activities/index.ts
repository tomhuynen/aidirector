import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
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

/**
 * @see \App\Http\Controllers\Admin\Activities\ViewController::view
 * @see app/Http/Controllers/Admin/Activities/ViewController.php:16
 * @route '/admin/activities/{activity}'
 */
export const view = (
  args:
    | { activity: string | number | { sqid: string | number } }
    | [activity: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/admin/activities/{activity}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Activities\ViewController::view
 * @see app/Http/Controllers/Admin/Activities/ViewController.php:16
 * @route '/admin/activities/{activity}'
 */
view.url = (
  args:
    | { activity: string | number | { sqid: string | number } }
    | [activity: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { activity: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { activity: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      activity: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    activity: typeof args.activity === 'object' ? args.activity.sqid : args.activity,
  }

  return (
    view.definition.url.replace('{activity}', parsedArgs.activity.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Activities\ViewController::view
 * @see app/Http/Controllers/Admin/Activities/ViewController.php:16
 * @route '/admin/activities/{activity}'
 */
view.get = (
  args:
    | { activity: string | number | { sqid: string | number } }
    | [activity: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Activities\ViewController::view
 * @see app/Http/Controllers/Admin/Activities/ViewController.php:16
 * @route '/admin/activities/{activity}'
 */
view.head = (
  args:
    | { activity: string | number | { sqid: string | number } }
    | [activity: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

const activities = {
  index: Object.assign(index, index),
  view: Object.assign(view, view),
}

export default activities
