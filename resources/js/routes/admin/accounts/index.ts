import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::create
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/create'
 */
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: create.url(options),
  method: 'get',
})

create.definition = {
  methods: ['get', 'head'],
  url: '/admin/accounts/create',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::create
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/create'
 */
create.url = (options?: RouteQueryOptions) => {
  return create.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::create
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/create'
 */
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: create.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::create
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/create'
 */
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: create.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::store
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:31
 * @route '/admin/accounts/create'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/admin/accounts/create',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::store
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:31
 * @route '/admin/accounts/create'
 */
store.url = (options?: RouteQueryOptions) => {
  return store.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::store
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:31
 * @route '/admin/accounts/create'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/{account}/update'
 */
export const update = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
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
  url: '/admin/accounts/{account}/update',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/{account}/update'
 */
update.url = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { account: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { account: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      account: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    account: typeof args.account === 'object' ? args.account.sqid : args.account,
  }

  return (
    update.definition.url.replace('{account}', parsedArgs.account.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/{account}/update'
 */
update.get = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: update.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/{account}/update'
 */
update.head = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: update.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\InviteController::invite
 * @see app/Http/Controllers/Admin/Accounts/InviteController.php:15
 * @route '/admin/accounts/{account}/invite'
 */
export const invite = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: invite.url(args, options),
  method: 'get',
})

invite.definition = {
  methods: ['get', 'head'],
  url: '/admin/accounts/{account}/invite',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\InviteController::invite
 * @see app/Http/Controllers/Admin/Accounts/InviteController.php:15
 * @route '/admin/accounts/{account}/invite'
 */
invite.url = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { account: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { account: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      account: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    account: typeof args.account === 'object' ? args.account.sqid : args.account,
  }

  return (
    invite.definition.url.replace('{account}', parsedArgs.account.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\InviteController::invite
 * @see app/Http/Controllers/Admin/Accounts/InviteController.php:15
 * @route '/admin/accounts/{account}/invite'
 */
invite.get = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: invite.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\InviteController::invite
 * @see app/Http/Controllers/Admin/Accounts/InviteController.php:15
 * @route '/admin/accounts/{account}/invite'
 */
invite.head = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: invite.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\ViewController::view
 * @see app/Http/Controllers/Admin/Accounts/ViewController.php:19
 * @route '/admin/accounts/{account}'
 */
export const view = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
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
  url: '/admin/accounts/{account}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\ViewController::view
 * @see app/Http/Controllers/Admin/Accounts/ViewController.php:19
 * @route '/admin/accounts/{account}'
 */
view.url = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { account: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { account: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      account: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    account: typeof args.account === 'object' ? args.account.sqid : args.account,
  }

  return (
    view.definition.url.replace('{account}', parsedArgs.account.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\ViewController::view
 * @see app/Http/Controllers/Admin/Accounts/ViewController.php:19
 * @route '/admin/accounts/{account}'
 */
view.get = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\ViewController::view
 * @see app/Http/Controllers/Admin/Accounts/ViewController.php:19
 * @route '/admin/accounts/{account}'
 */
view.head = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\IndexController::index
 * @see app/Http/Controllers/Admin/Accounts/IndexController.php:16
 * @route '/admin/accounts'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/admin/accounts',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\IndexController::index
 * @see app/Http/Controllers/Admin/Accounts/IndexController.php:16
 * @route '/admin/accounts'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\IndexController::index
 * @see app/Http/Controllers/Admin/Accounts/IndexController.php:16
 * @route '/admin/accounts'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\IndexController::index
 * @see app/Http/Controllers/Admin/Accounts/IndexController.php:16
 * @route '/admin/accounts'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\DestroyController::destroy
 * @see app/Http/Controllers/Admin/Accounts/DestroyController.php:14
 * @route '/admin/accounts/{account}'
 */
export const destroy = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
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
  url: '/admin/accounts/{account}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\DestroyController::destroy
 * @see app/Http/Controllers/Admin/Accounts/DestroyController.php:14
 * @route '/admin/accounts/{account}'
 */
destroy.url = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { account: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { account: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      account: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    account: typeof args.account === 'object' ? args.account.sqid : args.account,
  }

  return (
    destroy.definition.url.replace('{account}', parsedArgs.account.toString()).replace(/\/+$/, '') +
    queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\DestroyController::destroy
 * @see app/Http/Controllers/Admin/Accounts/DestroyController.php:14
 * @route '/admin/accounts/{account}'
 */
destroy.delete = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

const accounts = {
  create: Object.assign(create, create),
  store: Object.assign(store, store),
  update: Object.assign(update, update),
  invite: Object.assign(invite, invite),
  view: Object.assign(view, view),
  index: Object.assign(index, index),
  destroy: Object.assign(destroy, destroy),
}

export default accounts
