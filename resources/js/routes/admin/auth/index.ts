import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Auth\InviteController::invite
 * @see app/Http/Controllers/Admin/Auth/InviteController.php:18
 * @route '/auth/invite/{user}'
 */
export const invite = (
  args:
    | { user: string | number | { sqid: string | number } }
    | [user: string | number | { sqid: string | number }]
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
  url: '/auth/invite/{user}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Auth\InviteController::invite
 * @see app/Http/Controllers/Admin/Auth/InviteController.php:18
 * @route '/auth/invite/{user}'
 */
invite.url = (
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

  return invite.definition.url.replace('{user}', parsedArgs.user.toString()).replace(/\/+$/, '') + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Auth\InviteController::invite
 * @see app/Http/Controllers/Admin/Auth/InviteController.php:18
 * @route '/auth/invite/{user}'
 */
invite.get = (
  args:
    | { user: string | number | { sqid: string | number } }
    | [user: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: invite.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Auth\InviteController::invite
 * @see app/Http/Controllers/Admin/Auth/InviteController.php:18
 * @route '/auth/invite/{user}'
 */
invite.head = (
  args:
    | { user: string | number | { sqid: string | number } }
    | [user: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: invite.url(args, options),
  method: 'head',
})

const auth = {
  invite: Object.assign(invite, invite),
}

export default auth
