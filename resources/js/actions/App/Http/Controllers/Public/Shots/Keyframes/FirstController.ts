import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\FirstController::choose
 * @see app/Http/Controllers/Public/Shots/Keyframes/FirstController.php:29
 * @route '/projects/{project}/shots/{shot}/keyframes/first/choose'
 */
export const choose = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: choose.url(args, options),
  method: 'post',
})

choose.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/first/choose',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\FirstController::choose
 * @see app/Http/Controllers/Public/Shots/Keyframes/FirstController.php:29
 * @route '/projects/{project}/shots/{shot}/keyframes/first/choose'
 */
choose.url = (
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
    choose.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\FirstController::choose
 * @see app/Http/Controllers/Public/Shots/Keyframes/FirstController.php:29
 * @route '/projects/{project}/shots/{shot}/keyframes/first/choose'
 */
choose.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: choose.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\FirstController::more
 * @see app/Http/Controllers/Public/Shots/Keyframes/FirstController.php:53
 * @route '/projects/{project}/shots/{shot}/keyframes/first/more'
 */
export const more = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: more.url(args, options),
  method: 'post',
})

more.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/first/more',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\FirstController::more
 * @see app/Http/Controllers/Public/Shots/Keyframes/FirstController.php:53
 * @route '/projects/{project}/shots/{shot}/keyframes/first/more'
 */
more.url = (
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
    more.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\FirstController::more
 * @see app/Http/Controllers/Public/Shots/Keyframes/FirstController.php:53
 * @route '/projects/{project}/shots/{shot}/keyframes/first/more'
 */
more.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: more.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\FirstController::adjust
 * @see app/Http/Controllers/Public/Shots/Keyframes/FirstController.php:79
 * @route '/projects/{project}/shots/{shot}/keyframes/first/adjust'
 */
export const adjust = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: adjust.url(args, options),
  method: 'post',
})

adjust.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/first/adjust',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\FirstController::adjust
 * @see app/Http/Controllers/Public/Shots/Keyframes/FirstController.php:79
 * @route '/projects/{project}/shots/{shot}/keyframes/first/adjust'
 */
adjust.url = (
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
    adjust.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\FirstController::adjust
 * @see app/Http/Controllers/Public/Shots/Keyframes/FirstController.php:79
 * @route '/projects/{project}/shots/{shot}/keyframes/first/adjust'
 */
adjust.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: adjust.url(args, options),
  method: 'post',
})

const FirstController = { choose, more, adjust }

export default FirstController
