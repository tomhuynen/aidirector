import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\Elements\ViewController::create
 * @see app/Http/Controllers/Public/Projects/Elements/ViewController.php:26
 * @route '/projects/{project}/elements/create'
 */
export const create = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: create.url(args, options),
  method: 'get',
})

create.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/elements/create',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\ViewController::create
 * @see app/Http/Controllers/Public/Projects/Elements/ViewController.php:26
 * @route '/projects/{project}/elements/create'
 */
create.url = (
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
    create.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\ViewController::create
 * @see app/Http/Controllers/Public/Projects/Elements/ViewController.php:26
 * @route '/projects/{project}/elements/create'
 */
create.get = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: create.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\ViewController::create
 * @see app/Http/Controllers/Public/Projects/Elements/ViewController.php:26
 * @route '/projects/{project}/elements/create'
 */
create.head = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: create.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\ViewController::view
 * @see app/Http/Controllers/Public/Projects/Elements/ViewController.php:54
 * @route '/projects/{project}/elements/{element}'
 */
export const view = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/elements/{element}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\ViewController::view
 * @see app/Http/Controllers/Public/Projects/Elements/ViewController.php:54
 * @route '/projects/{project}/elements/{element}'
 */
view.url = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      element: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    element: typeof args.element === 'object' ? args.element.sqid : args.element,
  }

  return (
    view.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{element}', parsedArgs.element.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\ViewController::view
 * @see app/Http/Controllers/Public/Projects/Elements/ViewController.php:54
 * @route '/projects/{project}/elements/{element}'
 */
view.get = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\ViewController::view
 * @see app/Http/Controllers/Public/Projects/Elements/ViewController.php:54
 * @route '/projects/{project}/elements/{element}'
 */
view.head = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

const ViewController = { create, view }

export default ViewController
