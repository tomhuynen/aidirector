import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::round
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:39
 * @route '/projects/{project}/style/rounds'
 */
export const round = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: round.url(args, options),
  method: 'post',
})

round.definition = {
  methods: ['post'],
  url: '/projects/{project}/style/rounds',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::round
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:39
 * @route '/projects/{project}/style/rounds'
 */
round.url = (
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
    round.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::round
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:39
 * @route '/projects/{project}/style/rounds'
 */
round.post = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: round.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::options
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:29
 * @route '/projects/{project}/style/rounds/{round}'
 */
export const options = (
  args:
    | { project: string | number | { sqid: string | number }; round: string | number }
    | [project: string | number | { sqid: string | number }, round: string | number],
  routeOptions?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: options.url(args, routeOptions),
  method: 'get',
})

options.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/style/rounds/{round}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::options
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:29
 * @route '/projects/{project}/style/rounds/{round}'
 */
options.url = (
  args:
    | { project: string | number | { sqid: string | number }; round: string | number }
    | [project: string | number | { sqid: string | number }, round: string | number],
  routeOptions?: RouteQueryOptions,
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
    options.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{round}', parsedArgs.round.toString())
      .replace(/\/+$/, '') + queryParams(routeOptions)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::options
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:29
 * @route '/projects/{project}/style/rounds/{round}'
 */
options.get = (
  args:
    | { project: string | number | { sqid: string | number }; round: string | number }
    | [project: string | number | { sqid: string | number }, round: string | number],
  routeOptions?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: options.url(args, routeOptions),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Style\RoundController::options
 * @see app/Http/Controllers/Public/Projects/Style/RoundController.php:29
 * @route '/projects/{project}/style/rounds/{round}'
 */
options.head = (
  args:
    | { project: string | number | { sqid: string | number }; round: string | number }
    | [project: string | number | { sqid: string | number }, round: string | number],
  routeOptions?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: options.url(args, routeOptions),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Style\PinController::pin
 * @see app/Http/Controllers/Public/Projects/Style/PinController.php:23
 * @route '/projects/{project}/style/{styleOption}/pin'
 */
export const pin = (
  args:
    | { project: string | number | { sqid: string | number }; styleOption: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, styleOption: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: pin.url(args, options),
  method: 'post',
})

pin.definition = {
  methods: ['post'],
  url: '/projects/{project}/style/{styleOption}/pin',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\Style\PinController::pin
 * @see app/Http/Controllers/Public/Projects/Style/PinController.php:23
 * @route '/projects/{project}/style/{styleOption}/pin'
 */
pin.url = (
  args:
    | { project: string | number | { sqid: string | number }; styleOption: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, styleOption: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      styleOption: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    styleOption: typeof args.styleOption === 'object' ? args.styleOption.sqid : args.styleOption,
  }

  return (
    pin.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{styleOption}', parsedArgs.styleOption.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Style\PinController::pin
 * @see app/Http/Controllers/Public/Projects/Style/PinController.php:23
 * @route '/projects/{project}/style/{styleOption}/pin'
 */
pin.post = (
  args:
    | { project: string | number | { sqid: string | number }; styleOption: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, styleOption: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: pin.url(args, options),
  method: 'post',
})

const style = {
  round: Object.assign(round, round),
  options: Object.assign(options, options),
  pin: Object.assign(pin, pin),
}

export default style
