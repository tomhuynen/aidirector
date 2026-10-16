import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\CoverController::view
 * @see app/Http/Controllers/Public/Projects/CoverController.php:18
 * @route '/projects/{project}/cover'
 */
export const view = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
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
  url: '/projects/{project}/cover',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\CoverController::view
 * @see app/Http/Controllers/Public/Projects/CoverController.php:18
 * @route '/projects/{project}/cover'
 */
view.url = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { project: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { project: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      project: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
  }

  return (
    view.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\CoverController::view
 * @see app/Http/Controllers/Public/Projects/CoverController.php:18
 * @route '/projects/{project}/cover'
 */
view.get = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\CoverController::view
 * @see app/Http/Controllers/Public/Projects/CoverController.php:18
 * @route '/projects/{project}/cover'
 */
view.head = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

const cover = {
  view: Object.assign(view, view),
}

export default cover
