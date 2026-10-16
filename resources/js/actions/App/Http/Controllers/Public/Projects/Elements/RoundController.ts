import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\Elements\RoundController::show
 * @see app/Http/Controllers/Public/Projects/Elements/RoundController.php:20
 * @route '/projects/{project}/elements/rounds/{elementRound}'
 */
export const show = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: show.url(args, options),
  method: 'get',
})

show.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/elements/rounds/{elementRound}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\RoundController::show
 * @see app/Http/Controllers/Public/Projects/Elements/RoundController.php:20
 * @route '/projects/{project}/elements/rounds/{elementRound}'
 */
show.url = (
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
    show.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{elementRound}', parsedArgs.elementRound.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\RoundController::show
 * @see app/Http/Controllers/Public/Projects/Elements/RoundController.php:20
 * @route '/projects/{project}/elements/rounds/{elementRound}'
 */
show.get = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: show.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\RoundController::show
 * @see app/Http/Controllers/Public/Projects/Elements/RoundController.php:20
 * @route '/projects/{project}/elements/rounds/{elementRound}'
 */
show.head = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: show.url(args, options),
  method: 'head',
})

const RoundController = { show }

export default RoundController
