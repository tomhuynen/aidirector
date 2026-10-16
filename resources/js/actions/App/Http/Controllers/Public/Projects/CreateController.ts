import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::view
 * @see app/Http/Controllers/Public/Projects/CreateController.php:22
 * @route '/projects/create'
 */
export const view = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/projects/create',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::view
 * @see app/Http/Controllers/Public/Projects/CreateController.php:22
 * @route '/projects/create'
 */
view.url = (options?: RouteQueryOptions) => {
  return view.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::view
 * @see app/Http/Controllers/Public/Projects/CreateController.php:22
 * @route '/projects/create'
 */
view.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::view
 * @see app/Http/Controllers/Public/Projects/CreateController.php:22
 * @route '/projects/create'
 */
view.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: view.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::resume
 * @see app/Http/Controllers/Public/Projects/CreateController.php:38
 * @route '/projects/{project}/setup'
 */
export const resume = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: resume.url(args, options),
  method: 'get',
})

resume.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/setup',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::resume
 * @see app/Http/Controllers/Public/Projects/CreateController.php:38
 * @route '/projects/{project}/setup'
 */
resume.url = (
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
    resume.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::resume
 * @see app/Http/Controllers/Public/Projects/CreateController.php:38
 * @route '/projects/{project}/setup'
 */
resume.get = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: resume.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::resume
 * @see app/Http/Controllers/Public/Projects/CreateController.php:38
 * @route '/projects/{project}/setup'
 */
resume.head = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: resume.url(args, options),
  method: 'head',
})

const CreateController = { view, resume }

export default CreateController
