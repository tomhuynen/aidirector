import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Api\V1\ShotController::index
 * @see app/Http/Controllers/Api/V1/ShotController.php:20
 * @route '/api/v1/projects/{project}/shots'
 */
export const index = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: index.url(args, options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/api/v1/projects/{project}/shots',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Api\V1\ShotController::index
 * @see app/Http/Controllers/Api/V1/ShotController.php:20
 * @route '/api/v1/projects/{project}/shots'
 */
index.url = (
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
    index.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Api\V1\ShotController::index
 * @see app/Http/Controllers/Api/V1/ShotController.php:20
 * @route '/api/v1/projects/{project}/shots'
 */
index.get = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: index.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Api\V1\ShotController::index
 * @see app/Http/Controllers/Api/V1/ShotController.php:20
 * @route '/api/v1/projects/{project}/shots'
 */
index.head = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: index.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Api\V1\ShotController::assets
 * @see app/Http/Controllers/Api/V1/ShotController.php:29
 * @route '/api/v1/projects/{project}/shots/{shot}/assets'
 */
export const assets = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: assets.url(args, options),
  method: 'get',
})

assets.definition = {
  methods: ['get', 'head'],
  url: '/api/v1/projects/{project}/shots/{shot}/assets',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Api\V1\ShotController::assets
 * @see app/Http/Controllers/Api/V1/ShotController.php:29
 * @route '/api/v1/projects/{project}/shots/{shot}/assets'
 */
assets.url = (
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
    assets.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Api\V1\ShotController::assets
 * @see app/Http/Controllers/Api/V1/ShotController.php:29
 * @route '/api/v1/projects/{project}/shots/{shot}/assets'
 */
assets.get = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: assets.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Api\V1\ShotController::assets
 * @see app/Http/Controllers/Api/V1/ShotController.php:29
 * @route '/api/v1/projects/{project}/shots/{shot}/assets'
 */
assets.head = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: assets.url(args, options),
  method: 'head',
})

const shots = {
  index: Object.assign(index, index),
  assets: Object.assign(assets, assets),
}

export default shots
