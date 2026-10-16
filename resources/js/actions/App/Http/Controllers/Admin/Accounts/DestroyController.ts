import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
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

const DestroyController = { destroy }

export default DestroyController
