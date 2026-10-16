import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Settings\SecurityController::view
 * @see app/Http/Controllers/Admin/Settings/SecurityController.php:15
 * @route '/admin/settings/security'
 */
export const view = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/admin/settings/security',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Settings\SecurityController::view
 * @see app/Http/Controllers/Admin/Settings/SecurityController.php:15
 * @route '/admin/settings/security'
 */
view.url = (options?: RouteQueryOptions) => {
  return view.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Settings\SecurityController::view
 * @see app/Http/Controllers/Admin/Settings/SecurityController.php:15
 * @route '/admin/settings/security'
 */
view.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Settings\SecurityController::view
 * @see app/Http/Controllers/Admin/Settings/SecurityController.php:15
 * @route '/admin/settings/security'
 */
view.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: view.url(options),
  method: 'head',
})

const SecurityController = { view }

export default SecurityController
