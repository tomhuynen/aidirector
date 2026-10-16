import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::destroyMany
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:36
 * @route '/projects/{project}/shots'
 */
export const destroyMany = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroyMany.url(args, options),
  method: 'delete',
})

destroyMany.definition = {
  methods: ['delete'],
  url: '/projects/{project}/shots',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::destroyMany
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:36
 * @route '/projects/{project}/shots'
 */
destroyMany.url = (
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
    destroyMany.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') +
    queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::destroyMany
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:36
 * @route '/projects/{project}/shots'
 */
destroyMany.delete = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroyMany.url(args, options),
  method: 'delete',
})

/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:22
 * @route '/projects/{project}/shots/{shot}'
 */
export const destroy = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

destroy.definition = {
  methods: ['delete'],
  url: '/projects/{project}/shots/{shot}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:22
 * @route '/projects/{project}/shots/{shot}'
 */
destroy.url = (
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
    destroy.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:22
 * @route '/projects/{project}/shots/{shot}'
 */
destroy.delete = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

const DestroyController = { destroyMany, destroy }

export default DestroyController
