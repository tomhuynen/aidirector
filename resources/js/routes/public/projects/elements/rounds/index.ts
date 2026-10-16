import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\Elements\RoundController::view
 * @see app/Http/Controllers/Public/Projects/Elements/RoundController.php:20
 * @route '/projects/{project}/elements/rounds/{elementRound}'
 */
export const view = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/elements/rounds/{elementRound}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\RoundController::view
 * @see app/Http/Controllers/Public/Projects/Elements/RoundController.php:20
 * @route '/projects/{project}/elements/rounds/{elementRound}'
 */
view.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      elementRound: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    elementRound: typeof args.elementRound === 'object' ? args.elementRound.sqid : args.elementRound,
  }

  return (
    view.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{elementRound}', parsedArgs.elementRound.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\RoundController::view
 * @see app/Http/Controllers/Public/Projects/Elements/RoundController.php:20
 * @route '/projects/{project}/elements/rounds/{elementRound}'
 */
view.get = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\RoundController::view
 * @see app/Http/Controllers/Public/Projects/Elements/RoundController.php:20
 * @route '/projects/{project}/elements/rounds/{elementRound}'
 */
view.head = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\PickController::pick
 * @see app/Http/Controllers/Public/Projects/Elements/PickController.php:30
 * @route '/projects/{project}/elements/rounds/{elementRound}/pick'
 */
export const pick = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: pick.url(args, options),
  method: 'post',
})

pick.definition = {
  methods: ['post'],
  url: '/projects/{project}/elements/rounds/{elementRound}/pick',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\PickController::pick
 * @see app/Http/Controllers/Public/Projects/Elements/PickController.php:30
 * @route '/projects/{project}/elements/rounds/{elementRound}/pick'
 */
pick.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      elementRound: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    elementRound: typeof args.elementRound === 'object' ? args.elementRound.sqid : args.elementRound,
  }

  return (
    pick.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{elementRound}', parsedArgs.elementRound.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\PickController::pick
 * @see app/Http/Controllers/Public/Projects/Elements/PickController.php:30
 * @route '/projects/{project}/elements/rounds/{elementRound}/pick'
 */
pick.post = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: pick.url(args, options),
  method: 'post',
})

const rounds = {
  view: Object.assign(view, view),
  pick: Object.assign(pick, pick),
}

export default rounds
