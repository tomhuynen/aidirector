import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::many
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:36
 * @route '/projects/{project}/shots'
 */
export const many = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: many.url(args, options),
  method: 'delete',
})

many.definition = {
  methods: ['delete'],
  url: '/projects/{project}/shots',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::many
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:36
 * @route '/projects/{project}/shots'
 */
many.url = (
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
    many.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::many
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:36
 * @route '/projects/{project}/shots'
 */
many.delete = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: many.url(args, options),
  method: 'delete',
})
