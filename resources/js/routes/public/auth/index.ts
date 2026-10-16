import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
import forgotPassword2fbc69 from './forgot-password'
import loginDf2c2a from './login'
import register702019 from './register'
import resetPasswordD16f51 from './reset-password'
/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::login
 * @see app/Http/Controllers/Public/Auth/LoginController.php:15
 * @route '/login'
 */
export const login = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: login.url(options),
  method: 'get',
})

login.definition = {
  methods: ['get', 'head'],
  url: '/login',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::login
 * @see app/Http/Controllers/Public/Auth/LoginController.php:15
 * @route '/login'
 */
login.url = (options?: RouteQueryOptions) => {
  return login.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::login
 * @see app/Http/Controllers/Public/Auth/LoginController.php:15
 * @route '/login'
 */
login.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: login.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::login
 * @see app/Http/Controllers/Public/Auth/LoginController.php:15
 * @route '/login'
 */
login.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: login.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Auth\RegisterController::register
 * @see app/Http/Controllers/Public/Auth/RegisterController.php:17
 * @route '/register'
 */
export const register = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: register.url(options),
  method: 'get',
})

register.definition = {
  methods: ['get', 'head'],
  url: '/register',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Auth\RegisterController::register
 * @see app/Http/Controllers/Public/Auth/RegisterController.php:17
 * @route '/register'
 */
register.url = (options?: RouteQueryOptions) => {
  return register.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\RegisterController::register
 * @see app/Http/Controllers/Public/Auth/RegisterController.php:17
 * @route '/register'
 */
register.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: register.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Auth\RegisterController::register
 * @see app/Http/Controllers/Public/Auth/RegisterController.php:17
 * @route '/register'
 */
register.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: register.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Auth\ForgotPasswordController::forgotPassword
 * @see app/Http/Controllers/Public/Auth/ForgotPasswordController.php:13
 * @route '/forgot-password'
 */
export const forgotPassword = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: forgotPassword.url(options),
  method: 'get',
})

forgotPassword.definition = {
  methods: ['get', 'head'],
  url: '/forgot-password',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Auth\ForgotPasswordController::forgotPassword
 * @see app/Http/Controllers/Public/Auth/ForgotPasswordController.php:13
 * @route '/forgot-password'
 */
forgotPassword.url = (options?: RouteQueryOptions) => {
  return forgotPassword.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\ForgotPasswordController::forgotPassword
 * @see app/Http/Controllers/Public/Auth/ForgotPasswordController.php:13
 * @route '/forgot-password'
 */
forgotPassword.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: forgotPassword.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Auth\ForgotPasswordController::forgotPassword
 * @see app/Http/Controllers/Public/Auth/ForgotPasswordController.php:13
 * @route '/forgot-password'
 */
forgotPassword.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: forgotPassword.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Auth\ResetPasswordController::resetPassword
 * @see app/Http/Controllers/Public/Auth/ResetPasswordController.php:19
 * @route '/reset-password/{token}'
 */
export const resetPassword = (
  args: { token: string | number } | [token: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: resetPassword.url(args, options),
  method: 'get',
})

resetPassword.definition = {
  methods: ['get', 'head'],
  url: '/reset-password/{token}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Auth\ResetPasswordController::resetPassword
 * @see app/Http/Controllers/Public/Auth/ResetPasswordController.php:19
 * @route '/reset-password/{token}'
 */
resetPassword.url = (
  args: { token: string | number } | [token: string | number] | string | number,
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { token: args }
  }

  if (Array.isArray(args)) {
    args = {
      token: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    token: args.token,
  }

  return (
    resetPassword.definition.url.replace('{token}', parsedArgs.token.toString()).replace(/\/+$/, '') +
    queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Auth\ResetPasswordController::resetPassword
 * @see app/Http/Controllers/Public/Auth/ResetPasswordController.php:19
 * @route '/reset-password/{token}'
 */
resetPassword.get = (
  args: { token: string | number } | [token: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: resetPassword.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Auth\ResetPasswordController::resetPassword
 * @see app/Http/Controllers/Public/Auth/ResetPasswordController.php:19
 * @route '/reset-password/{token}'
 */
resetPassword.head = (
  args: { token: string | number } | [token: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: resetPassword.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::logout
 * @see app/Http/Controllers/Public/Auth/LoginController.php:37
 * @route '/logout'
 */
export const logout = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: logout.url(options),
  method: 'post',
})

logout.definition = {
  methods: ['post'],
  url: '/logout',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::logout
 * @see app/Http/Controllers/Public/Auth/LoginController.php:37
 * @route '/logout'
 */
logout.url = (options?: RouteQueryOptions) => {
  return logout.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Auth\LoginController::logout
 * @see app/Http/Controllers/Public/Auth/LoginController.php:37
 * @route '/logout'
 */
logout.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: logout.url(options),
  method: 'post',
})

const auth = {
  login: Object.assign(login, loginDf2c2a),
  register: Object.assign(register, register702019),
  forgotPassword: Object.assign(forgotPassword, forgotPassword2fbc69),
  resetPassword: Object.assign(resetPassword, resetPasswordD16f51),
  logout: Object.assign(logout, logout),
}

export default auth
