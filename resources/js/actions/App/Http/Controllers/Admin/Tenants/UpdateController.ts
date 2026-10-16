import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/create'
 */
const update10302edc96dbcc650d029532f2e7ed1d = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: update10302edc96dbcc650d029532f2e7ed1d.url(options),
  method: 'get',
})

update10302edc96dbcc650d029532f2e7ed1d.definition = {
  methods: ['get', 'head'],
  url: '/admin/tenants/create',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/create'
 */
update10302edc96dbcc650d029532f2e7ed1d.url = (options?: RouteQueryOptions) => {
  return update10302edc96dbcc650d029532f2e7ed1d.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/create'
 */
update10302edc96dbcc650d029532f2e7ed1d.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: update10302edc96dbcc650d029532f2e7ed1d.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/create'
 */
update10302edc96dbcc650d029532f2e7ed1d.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: update10302edc96dbcc650d029532f2e7ed1d.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/{tenant}/update'
 */
const update69f79a18c92f12ac195d728028c7be89 = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: update69f79a18c92f12ac195d728028c7be89.url(args, options),
  method: 'get',
})

update69f79a18c92f12ac195d728028c7be89.definition = {
  methods: ['get', 'head'],
  url: '/admin/tenants/{tenant}/update',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/{tenant}/update'
 */
update69f79a18c92f12ac195d728028c7be89.url = (
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
    update69f79a18c92f12ac195d728028c7be89.definition.url
      .replace('{tenant}', parsedArgs.tenant.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/{tenant}/update'
 */
update69f79a18c92f12ac195d728028c7be89.get = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: update69f79a18c92f12ac195d728028c7be89.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::update
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:17
 * @route '/admin/tenants/{tenant}/update'
 */
update69f79a18c92f12ac195d728028c7be89.head = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: update69f79a18c92f12ac195d728028c7be89.url(args, options),
  method: 'head',
})

/**
 * Multiple routes resolve to \App\Http\Controllers\Admin\Tenants\UpdateController::update, so this export is a
 * dictionary keyed by URI rather than a callable. Call a specific route with `update['<uri>'](...)`,
 * or import the route by name from your generated `routes/` directory.
 */
export const update = {
  '/admin/tenants/create': update10302edc96dbcc650d029532f2e7ed1d,
  '/admin/tenants/{tenant}/update': update69f79a18c92f12ac195d728028c7be89,
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::store
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:26
 * @route '/admin/tenants/create'
 */
const store10302edc96dbcc650d029532f2e7ed1d = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store10302edc96dbcc650d029532f2e7ed1d.url(options),
  method: 'post',
})

store10302edc96dbcc650d029532f2e7ed1d.definition = {
  methods: ['post'],
  url: '/admin/tenants/create',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::store
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:26
 * @route '/admin/tenants/create'
 */
store10302edc96dbcc650d029532f2e7ed1d.url = (options?: RouteQueryOptions) => {
  return store10302edc96dbcc650d029532f2e7ed1d.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::store
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:26
 * @route '/admin/tenants/create'
 */
store10302edc96dbcc650d029532f2e7ed1d.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store10302edc96dbcc650d029532f2e7ed1d.url(options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::store
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:26
 * @route '/admin/tenants/{tenant}/update'
 */
const store69f79a18c92f12ac195d728028c7be89 = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store69f79a18c92f12ac195d728028c7be89.url(args, options),
  method: 'post',
})

store69f79a18c92f12ac195d728028c7be89.definition = {
  methods: ['post'],
  url: '/admin/tenants/{tenant}/update',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::store
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:26
 * @route '/admin/tenants/{tenant}/update'
 */
store69f79a18c92f12ac195d728028c7be89.url = (
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
    store69f79a18c92f12ac195d728028c7be89.definition.url
      .replace('{tenant}', parsedArgs.tenant.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\UpdateController::store
 * @see app/Http/Controllers/Admin/Tenants/UpdateController.php:26
 * @route '/admin/tenants/{tenant}/update'
 */
store69f79a18c92f12ac195d728028c7be89.post = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store69f79a18c92f12ac195d728028c7be89.url(args, options),
  method: 'post',
})

/**
 * Multiple routes resolve to \App\Http\Controllers\Admin\Tenants\UpdateController::store, so this export is a
 * dictionary keyed by URI rather than a callable. Call a specific route with `store['<uri>'](...)`,
 * or import the route by name from your generated `routes/` directory.
 */
export const store = {
  '/admin/tenants/create': store10302edc96dbcc650d029532f2e7ed1d,
  '/admin/tenants/{tenant}/update': store69f79a18c92f12ac195d728028c7be89,
}

const UpdateController = { update, store }

export default UpdateController
