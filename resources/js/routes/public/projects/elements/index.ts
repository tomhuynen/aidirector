import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
import rounds from './rounds'
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
 * @see \App\Http\Controllers\Public\Projects\Elements\StoreController::store
 * @see app/Http/Controllers/Public/Projects/Elements/StoreController.php:25
 * @route '/projects/{project}/elements'
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
  url: '/projects/{project}/elements',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\StoreController::store
 * @see app/Http/Controllers/Public/Projects/Elements/StoreController.php:25
 * @route '/projects/{project}/elements'
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
 * @see \App\Http\Controllers\Public\Projects\Elements\StoreController::store
 * @see app/Http/Controllers/Public/Projects/Elements/StoreController.php:25
 * @route '/projects/{project}/elements'
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

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\UpdateController::update
 * @see app/Http/Controllers/Public/Projects/Elements/UpdateController.php:23
 * @route '/projects/{project}/elements/{element}'
 */
export const update = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: update.url(args, options),
  method: 'post',
})

update.definition = {
  methods: ['post'],
  url: '/projects/{project}/elements/{element}',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\UpdateController::update
 * @see app/Http/Controllers/Public/Projects/Elements/UpdateController.php:23
 * @route '/projects/{project}/elements/{element}'
 */
update.url = (
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
    update.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{element}', parsedArgs.element.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\UpdateController::update
 * @see app/Http/Controllers/Public/Projects/Elements/UpdateController.php:23
 * @route '/projects/{project}/elements/{element}'
 */
update.post = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: update.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\VersionController::version
 * @see app/Http/Controllers/Public/Projects/Elements/VersionController.php:19
 * @route '/projects/{project}/elements/{element}/version'
 */
export const version = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: version.url(args, options),
  method: 'post',
})

version.definition = {
  methods: ['post'],
  url: '/projects/{project}/elements/{element}/version',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\VersionController::version
 * @see app/Http/Controllers/Public/Projects/Elements/VersionController.php:19
 * @route '/projects/{project}/elements/{element}/version'
 */
version.url = (
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
    version.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{element}', parsedArgs.element.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\VersionController::version
 * @see app/Http/Controllers/Public/Projects/Elements/VersionController.php:19
 * @route '/projects/{project}/elements/{element}/version'
 */
version.post = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: version.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/Elements/DestroyController.php:18
 * @route '/projects/{project}/elements/{element}'
 */
export const destroy = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

destroy.definition = {
  methods: ['delete'],
  url: '/projects/{project}/elements/{element}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/Elements/DestroyController.php:18
 * @route '/projects/{project}/elements/{element}'
 */
destroy.url = (
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
    destroy.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{element}', parsedArgs.element.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/Elements/DestroyController.php:18
 * @route '/projects/{project}/elements/{element}'
 */
destroy.delete = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

const elements = {
  create: Object.assign(create, create),
  store: Object.assign(store, store),
  view: Object.assign(view, view),
  update: Object.assign(update, update),
  version: Object.assign(version, version),
  destroy: Object.assign(destroy, destroy),
  rounds: Object.assign(rounds, rounds),
}

export default elements
