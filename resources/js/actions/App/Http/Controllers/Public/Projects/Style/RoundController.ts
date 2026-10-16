import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::store
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:39
 * @route '/projects/{project}/style/rounds'
 */
export const store = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/projects/{project}/style/rounds',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::store
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:39
 * @route '/projects/{project}/style/rounds'
 */
store.url = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { project: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { project: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      project: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
  }

  return (
    store.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::store
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:39
 * @route '/projects/{project}/style/rounds'
 */
store.post = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::index
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:29
 * @route '/projects/{project}/style/rounds/{round}'
 */
export const index = (
  args:
    | { project: string | number | { sqid: string | number }; round: string | number }
    | [project: string | number | { sqid: string | number }, round: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: index.url(args, options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/style/rounds/{round}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::index
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:29
 * @route '/projects/{project}/style/rounds/{round}'
 */
index.url = (
  args:
    | { project: string | number | { sqid: string | number }; round: string | number }
    | [project: string | number | { sqid: string | number }, round: string | number],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      round: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    round: args.round,
  }

  return (
    index.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{round}', parsedArgs.round.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::index
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:29
 * @route '/projects/{project}/style/rounds/{round}'
 */
index.get = (
  args:
    | { project: string | number | { sqid: string | number }; round: string | number }
    | [project: string | number | { sqid: string | number }, round: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: index.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::index
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:29
 * @route '/projects/{project}/style/rounds/{round}'
 */
index.head = (
  args:
    | { project: string | number | { sqid: string | number }; round: string | number }
    | [project: string | number | { sqid: string | number }, round: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: index.url(args, options),
  method: 'head',
})

const RoundController = { store, index }

export default RoundController
