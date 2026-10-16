import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Auth\AuthenticateUsingPasskeyController::__invoke
 * @see app/Http/Controllers/Admin/Auth/AuthenticateUsingPasskeyController.php:19
 * @route '/auth/passkeys/login'
 */
const AuthenticateUsingPasskeyController = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: AuthenticateUsingPasskeyController.url(options),
  method: 'post',
})

AuthenticateUsingPasskeyController.definition = {
  methods: ['post'],
  url: '/auth/passkeys/login',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Admin\Auth\AuthenticateUsingPasskeyController::__invoke
 * @see app/Http/Controllers/Admin/Auth/AuthenticateUsingPasskeyController.php:19
 * @route '/auth/passkeys/login'
 */
AuthenticateUsingPasskeyController.url = (options?: RouteQueryOptions) => {
  return AuthenticateUsingPasskeyController.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Auth\AuthenticateUsingPasskeyController::__invoke
 * @see app/Http/Controllers/Admin/Auth/AuthenticateUsingPasskeyController.php:19
 * @route '/auth/passkeys/login'
 */
AuthenticateUsingPasskeyController.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: AuthenticateUsingPasskeyController.url(options),
  method: 'post',
})

export default AuthenticateUsingPasskeyController
