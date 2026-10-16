import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
  validateParameters,
} from './../../wayfinder'
import accounts from './accounts'
import activities from './activities'
import auth from './auth'
import config from './config'
import dashboard from './dashboard'
import impersonate1fe2a3 from './impersonate'
import search from './search'
import settings from './settings'
import system from './system'
import tenants from './tenants'
import uploads from './uploads'
import widgets from './widgets'
/**
 * @see \Lab404\Impersonate\Controllers\ImpersonateController::impersonate
 * @see vendor/lab404/laravel-impersonate/src/Controllers/ImpersonateController.php:32
 * @route '/admin/impersonate/take/{id}/{guardName?}'
 */
export const impersonate = (
  args: { id: string | number; guardName?: string | number } | [id: string | number, guardName: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: impersonate.url(args, options),
  method: 'get',
})

impersonate.definition = {
  methods: ['get', 'head'],
  url: '/admin/impersonate/take/{id}/{guardName?}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \Lab404\Impersonate\Controllers\ImpersonateController::impersonate
 * @see vendor/lab404/laravel-impersonate/src/Controllers/ImpersonateController.php:32
 * @route '/admin/impersonate/take/{id}/{guardName?}'
 */
impersonate.url = (
  args: { id: string | number; guardName?: string | number } | [id: string | number, guardName: string | number],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      id: args[0],
      guardName: args[1],
    }
  }

  args = applyUrlDefaults(args)

  validateParameters(args, ['guardName'])

  const parsedArgs = {
    id: args.id,
    guardName: args.guardName,
  }

  return (
    impersonate.definition.url
      .replace('{id}', parsedArgs.id.toString())
      .replace('{guardName?}', parsedArgs.guardName?.toString() ?? '')
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \Lab404\Impersonate\Controllers\ImpersonateController::impersonate
 * @see vendor/lab404/laravel-impersonate/src/Controllers/ImpersonateController.php:32
 * @route '/admin/impersonate/take/{id}/{guardName?}'
 */
impersonate.get = (
  args: { id: string | number; guardName?: string | number } | [id: string | number, guardName: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: impersonate.url(args, options),
  method: 'get',
})

/**
 * @see \Lab404\Impersonate\Controllers\ImpersonateController::impersonate
 * @see vendor/lab404/laravel-impersonate/src/Controllers/ImpersonateController.php:32
 * @route '/admin/impersonate/take/{id}/{guardName?}'
 */
impersonate.head = (
  args: { id: string | number; guardName?: string | number } | [id: string | number, guardName: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: impersonate.url(args, options),
  method: 'head',
})

const admin = {
  dashboard: Object.assign(dashboard, dashboard),
  accounts: Object.assign(accounts, accounts),
  activities: Object.assign(activities, activities),
  uploads: Object.assign(uploads, uploads),
  config: Object.assign(config, config),
  settings: Object.assign(settings, settings),
  tenants: Object.assign(tenants, tenants),
  system: Object.assign(system, system),
  widgets: Object.assign(widgets, widgets),
  search: Object.assign(search, search),
  impersonate: Object.assign(impersonate, impersonate1fe2a3),
  auth: Object.assign(auth, auth),
}

export default admin
