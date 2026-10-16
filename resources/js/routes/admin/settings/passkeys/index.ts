import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
import options from './options'
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

/**
 * @see \App\Http\Controllers\Admin\Settings\Passkeys\DeleteController::destroy
 * @see app/Http/Controllers/Admin/Settings/Passkeys/DeleteController.php:13
 * @route '/admin/settings/passkeys/{passkey}'
 */
export const destroy = (
  args:
    | { passkey: string | number | { sqid: string | number } }
    | [passkey: string | number | { sqid: string | number }]
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
  url: '/admin/settings/passkeys/{passkey}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Admin\Settings\Passkeys\DeleteController::destroy
 * @see app/Http/Controllers/Admin/Settings/Passkeys/DeleteController.php:13
 * @route '/admin/settings/passkeys/{passkey}'
 */
destroy.url = (
  args:
    | { passkey: string | number | { sqid: string | number } }
    | [passkey: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { passkey: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { passkey: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      passkey: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    passkey: typeof args.passkey === 'object' ? args.passkey.sqid : args.passkey,
  }

  return (
    destroy.definition.url.replace('{passkey}', parsedArgs.passkey.toString()).replace(/\/+$/, '') +
    queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\Settings\Passkeys\DeleteController::destroy
 * @see app/Http/Controllers/Admin/Settings/Passkeys/DeleteController.php:13
 * @route '/admin/settings/passkeys/{passkey}'
 */
destroy.delete = (
  args:
    | { passkey: string | number | { sqid: string | number } }
    | [passkey: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

const passkeys = {
  options: Object.assign(options, options),
  store: Object.assign(store, store),
  destroy: Object.assign(destroy, destroy),
}

export default passkeys
