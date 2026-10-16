import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\Video\GenerateController::generate
 * @see app/Http/Controllers/Public/Shots/Video/GenerateController.php:23
 * @route '/projects/{project}/shots/{shot}/video/generate'
 */
export const generate = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: generate.url(args, options),
  method: 'post',
})

generate.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/video/generate',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Video\GenerateController::generate
 * @see app/Http/Controllers/Public/Shots/Video/GenerateController.php:23
 * @route '/projects/{project}/shots/{shot}/video/generate'
 */
generate.url = (
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
    generate.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Video\GenerateController::generate
 * @see app/Http/Controllers/Public/Shots/Video/GenerateController.php:23
 * @route '/projects/{project}/shots/{shot}/video/generate'
 */
generate.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: generate.url(args, options),
  method: 'post',
})

const video = {
  generate: Object.assign(generate, generate),
}

export default video
