import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
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

const ViewController = { view }

export default ViewController
