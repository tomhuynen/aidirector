import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
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

const DestroyController = { destroy }

export default DestroyController
