import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/DestroyController.php:14
 * @route '/projects/{project}'
 */
export const destroy = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

destroy.definition = {
  methods: ['delete'],
  url: '/projects/{project}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Projects\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/DestroyController.php:14
 * @route '/projects/{project}'
 */
destroy.url = (
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
    destroy.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') +
    queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/DestroyController.php:14
 * @route '/projects/{project}'
 */
destroy.delete = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

const DestroyController = { destroy }

export default DestroyController
