import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\LayoutController::store
 * @see app/Http/Controllers/Public/Shots/Keyframes/LayoutController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/layout'
 */
export const store = (
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
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/{keyframe}/layout',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\LayoutController::store
 * @see app/Http/Controllers/Public/Shots/Keyframes/LayoutController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/layout'
 */
store.url = (
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
    store.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{keyframe}', parsedArgs.keyframe.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\LayoutController::store
 * @see app/Http/Controllers/Public/Shots/Keyframes/LayoutController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/layout'
 */
store.post = (
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
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

const LayoutController = { store }

export default LayoutController
