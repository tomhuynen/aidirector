import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Settings\Passkeys\OptionsController::create
 * @see app/Http/Controllers/Admin/Settings/Passkeys/OptionsController.php:13
 * @route '/admin/settings/passkeys/options/create'
 */
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: create.url(options),
  method: 'get',
})

create.definition = {
  methods: ['get', 'head'],
  url: '/admin/settings/passkeys/options/create',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\Settings\Passkeys\OptionsController::create
 * @see app/Http/Controllers/Admin/Settings/Passkeys/OptionsController.php:13
 * @route '/admin/settings/passkeys/options/create'
 */
create.url = (options?: RouteQueryOptions) => {
  return create.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Settings\Passkeys\OptionsController::create
 * @see app/Http/Controllers/Admin/Settings/Passkeys/OptionsController.php:13
 * @route '/admin/settings/passkeys/options/create'
 */
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: create.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\Settings\Passkeys\OptionsController::create
 * @see app/Http/Controllers/Admin/Settings/Passkeys/OptionsController.php:13
 * @route '/admin/settings/passkeys/options/create'
 */
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: create.url(options),
  method: 'head',
})

const OptionsController = { create }

export default OptionsController
