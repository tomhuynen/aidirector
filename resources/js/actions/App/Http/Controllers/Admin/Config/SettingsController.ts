import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Config\SettingsController::index
 * @see app/Http/Controllers/Admin/Config/SettingsController.php:13
 * @route '/admin/config/settings'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/admin/config/settings',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Config\SettingsController::index
 * @see app/Http/Controllers/Admin/Config/SettingsController.php:13
 * @route '/admin/config/settings'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Config\SettingsController::index
 * @see app/Http/Controllers/Admin/Config/SettingsController.php:13
 * @route '/admin/config/settings'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Config\SettingsController::index
 * @see app/Http/Controllers/Admin/Config/SettingsController.php:13
 * @route '/admin/config/settings'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

const SettingsController = { index }

export default SettingsController
