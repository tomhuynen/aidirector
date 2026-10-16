import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\Branding\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/Branding/DestroyController.php:17
 * @route '/projects/{project}/branding/{logo}'
 */
export const destroy = (
  args:
    | { project: string | number | { sqid: string | number }; logo: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, logo: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

destroy.definition = {
  methods: ['delete'],
  url: '/projects/{project}/branding/{logo}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Projects\Branding\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/Branding/DestroyController.php:17
 * @route '/projects/{project}/branding/{logo}'
 */
destroy.url = (
  args:
    | { project: string | number | { sqid: string | number }; logo: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, logo: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      logo: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    logo: typeof args.logo === 'object' ? args.logo.sqid : args.logo,
  }

  return (
    destroy.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{logo}', parsedArgs.logo.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Branding\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/Branding/DestroyController.php:17
 * @route '/projects/{project}/branding/{logo}'
 */
destroy.delete = (
  args:
    | { project: string | number | { sqid: string | number }; logo: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, logo: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

const DestroyController = { destroy }

export default DestroyController
