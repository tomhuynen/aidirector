import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Tenants\SwitchController::update
 * @see app/Http/Controllers/Admin/Tenants/SwitchController.php:14
 * @route '/admin/tenants/{tenant}/switch'
 */
export const update = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'patch'> => ({
  url: update.url(args, options),
  method: 'patch',
})

update.definition = {
  methods: ['patch'],
  url: '/admin/tenants/{tenant}/switch',
} satisfies RouteDefinition<['patch']>

/**
 * @see \App\Http\Controllers\Admin\Tenants\SwitchController::update
 * @see app/Http/Controllers/Admin/Tenants/SwitchController.php:14
 * @route '/admin/tenants/{tenant}/switch'
 */
update.url = (
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
    update.definition.url.replace('{tenant}', parsedArgs.tenant.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Tenants\SwitchController::update
 * @see app/Http/Controllers/Admin/Tenants/SwitchController.php:14
 * @route '/admin/tenants/{tenant}/switch'
 */
update.patch = (
  args:
    | { tenant: string | number | { sqid: string | number } }
    | [tenant: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'patch'> => ({
  url: update.url(args, options),
  method: 'patch',
})

const SwitchController = { update }

export default SwitchController
