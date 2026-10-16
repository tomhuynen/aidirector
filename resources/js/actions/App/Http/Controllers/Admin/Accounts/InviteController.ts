import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
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

const InviteController = { invite }

export default InviteController
