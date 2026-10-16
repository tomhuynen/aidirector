import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
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

const ViewController = { view }

export default ViewController
