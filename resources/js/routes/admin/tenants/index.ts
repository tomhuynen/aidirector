import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Tenants\IndexController::index
 * @see app/Http/Controllers/Admin/Tenants/IndexController.php:16
 * @route '/admin/tenants'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/admin/tenants',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\IndexController::index
 * @see app/Http/Controllers/Admin/Tenants/IndexController.php:16
 * @route '/admin/tenants'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\IndexController::index
 * @see app/Http/Controllers/Admin/Tenants/IndexController.php:16
 * @route '/admin/tenants'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\IndexController::index
 * @see app/Http/Controllers/Admin/Tenants/IndexController.php:16
 * @route '/admin/tenants'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::create
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/create'
 */
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: create.url(options),
  method: 'get',
})

create.definition = {
  methods: ['get', 'head'],
  url: '/admin/tenants/create',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::create
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/create'
 */
create.url = (options?: RouteQueryOptions) => {
  return create.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::create
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/create'
 */
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: create.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::create
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/create'
 */
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: create.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::store
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:26
 * @route '/admin/tenants/create'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/admin/tenants/create',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::store
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:26
 * @route '/admin/tenants/create'
 */
store.url = (options?: RouteQueryOptions) => {
  return store.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::store
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:26
 * @route '/admin/tenants/create'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\ViewController::view
 * @see app/Http/Controllers/Admin/Tenants/ViewController.php:17
 * @route '/admin/tenants/{tenant}'
 */
export const view = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/admin/tenants/{tenant}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\ViewController::view
 * @see app/Http/Controllers/Admin/Tenants/ViewController.php:17
 * @route '/admin/tenants/{tenant}'
 */
view.url = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { tenant: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { tenant: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      tenant: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    tenant: typeof args.tenant === 'object' ? args.tenant.sqid : args.tenant,
  }

  return (
    view.definition.url.replace('{tenant}', parsedArgs.tenant.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\ViewController::view
 * @see app/Http/Controllers/Admin/Tenants/ViewController.php:17
 * @route '/admin/tenants/{tenant}'
 */
view.get = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\ViewController::view
 * @see app/Http/Controllers/Admin/Tenants/ViewController.php:17
 * @route '/admin/tenants/{tenant}'
 */
view.head = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\DestroyController::destroy
 * @see app/Http/Controllers/Admin/Tenants/DestroyController.php:14
 * @route '/admin/tenants/{tenant}'
 */
export const destroy = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

destroy.definition = {
  methods: ['delete'],
  url: '/admin/tenants/{tenant}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\DestroyController::destroy
 * @see app/Http/Controllers/Admin/Tenants/DestroyController.php:14
 * @route '/admin/tenants/{tenant}'
 */
destroy.url = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { tenant: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { tenant: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      tenant: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    tenant: typeof args.tenant === 'object' ? args.tenant.sqid : args.tenant,
  }

  return (
    destroy.definition.url.replace('{tenant}', parsedArgs.tenant.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\DestroyController::destroy
 * @see app/Http/Controllers/Admin/Tenants/DestroyController.php:14
 * @route '/admin/tenants/{tenant}'
 */
destroy.delete = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/{tenant}/update'
 */
export const update = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: update.url(args, options),
  method: 'get',
})

update.definition = {
  methods: ['get', 'head'],
  url: '/admin/tenants/{tenant}/update',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/{tenant}/update'
 */
update.url = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { tenant: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { tenant: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      tenant: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    tenant: typeof args.tenant === 'object' ? args.tenant.sqid : args.tenant,
  }

  return (
    update.definition.url.replace('{tenant}', parsedArgs.tenant.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/{tenant}/update'
 */
update.get = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: update.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/{tenant}/update'
 */
update.head = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: update.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\SwitchController::switchMethod
 * @see app/Http/Controllers/Admin/Tenants/SwitchController.php:14
 * @route '/admin/tenants/{tenant}/switch'
 */
export const switchMethod = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'patch'> => ({
  url: switchMethod.url(args, options),
  method: 'patch',
})

switchMethod.definition = {
  methods: ['patch'],
  url: '/admin/tenants/{tenant}/switch',
} satisfies RouteDefinition<['patch']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\SwitchController::switchMethod
 * @see app/Http/Controllers/Admin/Tenants/SwitchController.php:14
 * @route '/admin/tenants/{tenant}/switch'
 */
switchMethod.url = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { tenant: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { tenant: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      tenant: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    tenant: typeof args.tenant === 'object' ? args.tenant.sqid : args.tenant,
  }

  return (
    switchMethod.definition.url.replace('{tenant}', parsedArgs.tenant.toString()).replace(/\/+$/, '') +
    queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\SwitchController::switchMethod
 * @see app/Http/Controllers/Admin/Tenants/SwitchController.php:14
 * @route '/admin/tenants/{tenant}/switch'
 */
switchMethod.patch = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'patch'> => ({
  url: switchMethod.url(args, options),
  method: 'patch',
})

const tenants = {
  index: Object.assign(index, index),
  create: Object.assign(create, create),
  store: Object.assign(store, store),
  view: Object.assign(view, view),
  destroy: Object.assign(destroy, destroy),
  update: Object.assign(update, update),
  switch: Object.assign(switchMethod, switchMethod),
}

export default tenants
