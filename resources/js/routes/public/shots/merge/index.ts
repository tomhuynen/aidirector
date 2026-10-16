import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\MergeController::update
 * @see app/Http/Controllers/Public/Shots/MergeController.php:41
 * @route '/projects/{project}/shots/{shot}/merge'
 */
export const update = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: update.url(args, options),
  method: 'post',
})

update.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/merge',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\MergeController::update
 * @see app/Http/Controllers/Public/Shots/MergeController.php:41
 * @route '/projects/{project}/shots/{shot}/merge'
 */
update.url = (
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
    update.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\MergeController::update
 * @see app/Http/Controllers/Public/Shots/MergeController.php:41
 * @route '/projects/{project}/shots/{shot}/merge'
 */
update.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: update.url(args, options),
  method: 'post',
})

const merge = {
  update: Object.assign(update, update),
}

export default merge
