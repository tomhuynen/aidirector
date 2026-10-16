import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Settings\Passkeys\CreateController::store
 * @see app/Http/Controllers/Admin/Settings/Passkeys/CreateController.php:16
 * @route '/admin/settings/passkeys/create'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/admin/settings/passkeys/create',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Admin\Settings\Passkeys\CreateController::store
 * @see app/Http/Controllers/Admin/Settings/Passkeys/CreateController.php:16
 * @route '/admin/settings/passkeys/create'
 */
store.url = (options?: RouteQueryOptions) => {
  return store.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Settings\Passkeys\CreateController::store
 * @see app/Http/Controllers/Admin/Settings/Passkeys/CreateController.php:16
 * @route '/admin/settings/passkeys/create'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

const CreateController = { store }

export default CreateController
