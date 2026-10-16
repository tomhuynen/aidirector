import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Auth\InviteController::store
 * @see app/Http/Controllers/Admin/Auth/InviteController.php:30
 * @route '/auth/invite/{user}'
 */
export const store = (
  args:
    | { user: string | number | { sqid: string | number } }
    | [user: string | number | { sqid: string | number }]
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
  url: '/auth/invite/{user}',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Admin\Auth\InviteController::store
 * @see app/Http/Controllers/Admin/Auth/InviteController.php:30
 * @route '/auth/invite/{user}'
 */
store.url = (
  args:
    | { user: string | number | { sqid: string | number } }
    | [user: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { user: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { user: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      user: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    user: typeof args.user === 'object' ? args.user.sqid : args.user,
  }

  return store.definition.url.replace('{user}', parsedArgs.user.toString()).replace(/\/+$/, '') + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Auth\InviteController::store
 * @see app/Http/Controllers/Admin/Auth/InviteController.php:30
 * @route '/auth/invite/{user}'
 */
store.post = (
  args:
    | { user: string | number | { sqid: string | number } }
    | [user: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Admin\Auth\InviteController::view
 * @see app/Http/Controllers/Admin/Auth/InviteController.php:18
 * @route '/auth/invite/{user}'
 */
export const view = (
  args:
    | { user: string | number | { sqid: string | number } }
    | [user: string | number | { sqid: string | number }]
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
  url: '/auth/invite/{user}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Auth\InviteController::view
 * @see app/Http/Controllers/Admin/Auth/InviteController.php:18
 * @route '/auth/invite/{user}'
 */
view.url = (
  args:
    | { user: string | number | { sqid: string | number } }
    | [user: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { user: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { user: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      user: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    user: typeof args.user === 'object' ? args.user.sqid : args.user,
  }

  return view.definition.url.replace('{user}', parsedArgs.user.toString()).replace(/\/+$/, '') + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Auth\InviteController::view
 * @see app/Http/Controllers/Admin/Auth/InviteController.php:18
 * @route '/auth/invite/{user}'
 */
view.get = (
  args:
    | { user: string | number | { sqid: string | number } }
    | [user: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Auth\InviteController::view
 * @see app/Http/Controllers/Admin/Auth/InviteController.php:18
 * @route '/auth/invite/{user}'
 */
view.head = (
  args:
    | { user: string | number | { sqid: string | number } }
    | [user: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

const InviteController = { store, view }

export default InviteController
