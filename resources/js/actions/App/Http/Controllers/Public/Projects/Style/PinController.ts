import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\Style\PinController::store
 * @see app/Http/Controllers/Public/Projects/Style/PinController.php:23
 * @route '/projects/{project}/style/{styleOption}/pin'
 */
export const store = (
  args:
    | { project: string | number | { sqid: string | number }; styleOption: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, styleOption: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/projects/{project}/style/{styleOption}/pin',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\Style\PinController::store
 * @see app/Http/Controllers/Public/Projects/Style/PinController.php:23
 * @route '/projects/{project}/style/{styleOption}/pin'
 */
store.url = (
  args:
    | { project: string | number | { sqid: string | number }; styleOption: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, styleOption: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      styleOption: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    styleOption: typeof args.styleOption === 'object' ? args.styleOption.sqid : args.styleOption,
  }

  return (
    store.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{styleOption}', parsedArgs.styleOption.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Style\PinController::store
 * @see app/Http/Controllers/Public/Projects/Style/PinController.php:23
 * @route '/projects/{project}/style/{styleOption}/pin'
 */
store.post = (
  args:
    | { project: string | number | { sqid: string | number }; styleOption: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, styleOption: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

const PinController = { store }

export default PinController
