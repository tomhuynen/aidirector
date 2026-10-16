import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\DurationController::store
 * @see app/Http/Controllers/Public/Shots/DurationController.php:25
 * @route '/projects/{project}/shots/{shot}/duration'
 */
export const store = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/duration',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\DurationController::store
 * @see app/Http/Controllers/Public/Shots/DurationController.php:25
 * @route '/projects/{project}/shots/{shot}/duration'
 */
store.url = (
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
    store.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\DurationController::store
 * @see app/Http/Controllers/Public/Shots/DurationController.php:25
 * @route '/projects/{project}/shots/{shot}/duration'
 */
store.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

const DurationController = { store }

export default DurationController
