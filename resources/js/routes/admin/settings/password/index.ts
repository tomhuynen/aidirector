import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Settings\PasswordController::edit
 * @see app/Http/Controllers/Admin/Settings/PasswordController.php:21
 * @route '/admin/settings/password'
 */
export const edit = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: edit.url(options),
  method: 'get',
})

edit.definition = {
  methods: ['get', 'head'],
  url: '/admin/settings/password',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Settings\PasswordController::edit
 * @see app/Http/Controllers/Admin/Settings/PasswordController.php:21
 * @route '/admin/settings/password'
 */
edit.url = (options?: RouteQueryOptions) => {
  return edit.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Settings\PasswordController::edit
 * @see app/Http/Controllers/Admin/Settings/PasswordController.php:21
 * @route '/admin/settings/password'
 */
edit.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: edit.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Settings\PasswordController::edit
 * @see app/Http/Controllers/Admin/Settings/PasswordController.php:21
 * @route '/admin/settings/password'
 */
edit.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: edit.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\Settings\PasswordController::update
 * @see app/Http/Controllers/Admin/Settings/PasswordController.php:31
 * @route '/admin/settings/password'
 */
export const update = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
  url: update.url(options),
  method: 'put',
})

update.definition = {
  methods: ['put'],
  url: '/admin/settings/password',
} satisfies RouteDefinition<['put']>

/**
 * @see \App\Http\Controllers\Admin\Settings\PasswordController::update
 * @see app/Http/Controllers/Admin/Settings/PasswordController.php:31
 * @route '/admin/settings/password'
 */
update.url = (options?: RouteQueryOptions) => {
  return update.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Settings\PasswordController::update
 * @see app/Http/Controllers/Admin/Settings/PasswordController.php:31
 * @route '/admin/settings/password'
 */
update.put = (options?: RouteQueryOptions): RouteDefinition<'put'> => ({
  url: update.url(options),
  method: 'put',
})

const password = {
  edit: Object.assign(edit, edit),
  update: Object.assign(update, update),
}

export default password
