import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/create'
 */
const update6dc1fd09ba8b53e6ec2b917d23593ed1 = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: update6dc1fd09ba8b53e6ec2b917d23593ed1.url(options),
  method: 'get',
})

update6dc1fd09ba8b53e6ec2b917d23593ed1.definition = {
  methods: ['get', 'head'],
  url: '/admin/accounts/create',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/create'
 */
update6dc1fd09ba8b53e6ec2b917d23593ed1.url = (options?: RouteQueryOptions) => {
  return update6dc1fd09ba8b53e6ec2b917d23593ed1.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/create'
 */
update6dc1fd09ba8b53e6ec2b917d23593ed1.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: update6dc1fd09ba8b53e6ec2b917d23593ed1.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/create'
 */
update6dc1fd09ba8b53e6ec2b917d23593ed1.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: update6dc1fd09ba8b53e6ec2b917d23593ed1.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/{account}/update'
 */
const update085b99daafb7597a204bb355f44ee851 = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: update085b99daafb7597a204bb355f44ee851.url(args, options),
  method: 'get',
})

update085b99daafb7597a204bb355f44ee851.definition = {
  methods: ['get', 'head'],
  url: '/admin/accounts/{account}/update',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/{account}/update'
 */
update085b99daafb7597a204bb355f44ee851.url = (
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
    update085b99daafb7597a204bb355f44ee851.definition.url
      .replace('{account}', parsedArgs.account.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/{account}/update'
 */
update085b99daafb7597a204bb355f44ee851.get = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: update085b99daafb7597a204bb355f44ee851.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::update
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:21
 * @route '/admin/accounts/{account}/update'
 */
update085b99daafb7597a204bb355f44ee851.head = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: update085b99daafb7597a204bb355f44ee851.url(args, options),
  method: 'head',
})

/**
 * Multiple routes resolve to \App\Http\Controllers\Admin\Accounts\UpdateController::update, so this export is a
 * dictionary keyed by URI rather than a callable. Call a specific route with `update['<uri>'](...)`,
 * or import the route by name from your generated `routes/` directory.
 */
export const update = {
  '/admin/accounts/create': update6dc1fd09ba8b53e6ec2b917d23593ed1,
  '/admin/accounts/{account}/update': update085b99daafb7597a204bb355f44ee851,
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::store
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:31
 * @route '/admin/accounts/create'
 */
const store6dc1fd09ba8b53e6ec2b917d23593ed1 = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store6dc1fd09ba8b53e6ec2b917d23593ed1.url(options),
  method: 'post',
})

store6dc1fd09ba8b53e6ec2b917d23593ed1.definition = {
  methods: ['post'],
  url: '/admin/accounts/create',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::store
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:31
 * @route '/admin/accounts/create'
 */
store6dc1fd09ba8b53e6ec2b917d23593ed1.url = (options?: RouteQueryOptions) => {
  return store6dc1fd09ba8b53e6ec2b917d23593ed1.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::store
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:31
 * @route '/admin/accounts/create'
 */
store6dc1fd09ba8b53e6ec2b917d23593ed1.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store6dc1fd09ba8b53e6ec2b917d23593ed1.url(options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::store
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:31
 * @route '/admin/accounts/{account}/update'
 */
const store085b99daafb7597a204bb355f44ee851 = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store085b99daafb7597a204bb355f44ee851.url(args, options),
  method: 'post',
})

store085b99daafb7597a204bb355f44ee851.definition = {
  methods: ['post'],
  url: '/admin/accounts/{account}/update',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::store
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:31
 * @route '/admin/accounts/{account}/update'
 */
store085b99daafb7597a204bb355f44ee851.url = (
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
    store085b99daafb7597a204bb355f44ee851.definition.url
      .replace('{account}', parsedArgs.account.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Accounts\UpdateController::store
 * @see app/Http/Controllers/Admin/Accounts/UpdateController.php:31
 * @route '/admin/accounts/{account}/update'
 */
store085b99daafb7597a204bb355f44ee851.post = (
  args:
    | { account: string | number | { sqid: string | number } }
    | [account: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store085b99daafb7597a204bb355f44ee851.url(args, options),
  method: 'post',
})

/**
 * Multiple routes resolve to \App\Http\Controllers\Admin\Accounts\UpdateController::store, so this export is a
 * dictionary keyed by URI rather than a callable. Call a specific route with `store['<uri>'](...)`,
 * or import the route by name from your generated `routes/` directory.
 */
export const store = {
  '/admin/accounts/create': store6dc1fd09ba8b53e6ec2b917d23593ed1,
  '/admin/accounts/{account}/update': store085b99daafb7597a204bb355f44ee851,
}

const UpdateController = { update, store }

export default UpdateController
