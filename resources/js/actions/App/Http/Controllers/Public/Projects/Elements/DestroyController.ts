import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\Elements\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/Elements/DestroyController.php:18
 * @route '/projects/{project}/elements/{element}'
 */
export const destroy = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

destroy.definition = {
  methods: ['delete'],
  url: '/projects/{project}/elements/{element}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/Elements/DestroyController.php:18
 * @route '/projects/{project}/elements/{element}'
 */
destroy.url = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      element: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    element: typeof args.element === 'object' ? args.element.sqid : args.element,
  }

  return (
    destroy.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{element}', parsedArgs.element.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/Elements/DestroyController.php:18
 * @route '/projects/{project}/elements/{element}'
 */
destroy.delete = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

const DestroyController = { destroy }

export default DestroyController
