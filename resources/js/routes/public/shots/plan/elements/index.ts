import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\Plan\ElementsController::skip
 * @see app/Http/Controllers/Public/Shots/Plan/ElementsController.php:75
 * @route '/projects/{project}/shots/{shot}/plan/elements'
 */
export const skip = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: skip.url(args, options),
  method: 'delete',
})

skip.definition = {
  methods: ['delete'],
  url: '/projects/{project}/shots/{shot}/plan/elements',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Shots\Plan\ElementsController::skip
 * @see app/Http/Controllers/Public/Shots/Plan/ElementsController.php:75
 * @route '/projects/{project}/shots/{shot}/plan/elements'
 */
skip.url = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
  }

  return (
    skip.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Plan\ElementsController::skip
 * @see app/Http/Controllers/Public/Shots/Plan/ElementsController.php:75
 * @route '/projects/{project}/shots/{shot}/plan/elements'
 */
skip.delete = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: skip.url(args, options),
  method: 'delete',
})

const elements = {
  skip: Object.assign(skip, skip),
}

export default elements
