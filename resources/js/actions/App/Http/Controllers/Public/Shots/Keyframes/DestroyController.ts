import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/Keyframes/DestroyController.php:21
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}'
 */
export const destroy = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

destroy.definition = {
  methods: ['delete'],
  url: '/projects/{project}/shots/{shot}/keyframes/{keyframe}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/Keyframes/DestroyController.php:21
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}'
 */
destroy.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      keyframe: args[2],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    keyframe: typeof args.keyframe === 'object' ? args.keyframe.sqid : args.keyframe,
  }

  return (
    destroy.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{keyframe}', parsedArgs.keyframe.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/Keyframes/DestroyController.php:21
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}'
 */
destroy.delete = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

const DestroyController = { destroy }

export default DestroyController
