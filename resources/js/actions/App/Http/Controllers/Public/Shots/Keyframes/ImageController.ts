import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
  validateParameters,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\ImageController::view
 * @see app/Http/Controllers/Public/Shots/Keyframes/ImageController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/image/{conversion?}'
 */
export const view = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
        conversion?: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
        conversion: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/shots/{shot}/keyframes/{keyframe}/image/{conversion?}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\ImageController::view
 * @see app/Http/Controllers/Public/Shots/Keyframes/ImageController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/image/{conversion?}'
 */
view.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
        conversion?: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
        conversion: string | number,
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      keyframe: args[2],
      conversion: args[3],
    }
  }

  args = applyUrlDefaults(args)

  validateParameters(args, ['conversion'])

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    keyframe: typeof args.keyframe === 'object' ? args.keyframe.sqid : args.keyframe,
    conversion: args.conversion,
  }

  return (
    view.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{keyframe}', parsedArgs.keyframe.toString())
      .replace('{conversion?}', parsedArgs.conversion?.toString() ?? '')
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\ImageController::view
 * @see app/Http/Controllers/Public/Shots/Keyframes/ImageController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/image/{conversion?}'
 */
view.get = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
        conversion?: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
        conversion: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\ImageController::view
 * @see app/Http/Controllers/Public/Shots/Keyframes/ImageController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/image/{conversion?}'
 */
view.head = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
        conversion?: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
        conversion: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

const ImageController = { view }

export default ImageController
