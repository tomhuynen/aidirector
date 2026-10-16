import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\CoverController::show
 * @see app/Http/Controllers/Public/Projects/CoverController.php:18
 * @route '/projects/{project}/cover'
 */
export const show = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: show.url(args, options),
  method: 'get',
})

show.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/cover',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\CoverController::show
 * @see app/Http/Controllers/Public/Projects/CoverController.php:18
 * @route '/projects/{project}/cover'
 */
show.url = (
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
    show.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\CoverController::show
 * @see app/Http/Controllers/Public/Projects/CoverController.php:18
 * @route '/projects/{project}/cover'
 */
show.get = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: show.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\CoverController::show
 * @see app/Http/Controllers/Public/Projects/CoverController.php:18
 * @route '/projects/{project}/cover'
 */
show.head = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: show.url(args, options),
  method: 'head',
})

const CoverController = { show }

export default CoverController
