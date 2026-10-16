import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
  validateParameters,
} from './../wayfinder'
/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::login
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
 * @route '/auth/login'
 */
export const login = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: login.url(options),
  method: 'get',
})

login.definition = {
  methods: ['get', 'head'],
  url: '/auth/login',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::login
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
 * @route '/auth/login'
 */
login.url = (options?: RouteQueryOptions) => {
  return login.definition.url + queryParams(options)
}

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::login
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
 * @route '/auth/login'
 */
login.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: login.url(options),
  method: 'get',
})

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::login
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:47
 * @route '/auth/login'
 */
login.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: login.url(options),
  method: 'head',
})

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::logout
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
 * @route '/auth/logout'
 */
export const logout = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: logout.url(options),
  method: 'post',
})

logout.definition = {
  methods: ['post'],
  url: '/auth/logout',
} satisfies RouteDefinition<['post']>

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::logout
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
 * @route '/auth/logout'
 */
logout.url = (options?: RouteQueryOptions) => {
  return logout.definition.url + queryParams(options)
}

/**
 * @see \Laravel\Fortify\Http\Controllers\AuthenticatedSessionController::logout
 * @see vendor/laravel/fortify/src/Http/Controllers/AuthenticatedSessionController.php:100
 * @route '/auth/logout'
 */
logout.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: logout.url(options),
  method: 'post',
})

/**
 * @see \Laravel\Telescope\Http\Controllers\HomeController::telescope
 * @see vendor/laravel/telescope/src/Http/Controllers/HomeController.php:15
 * @route '/telescope/{view?}'
 */
export const telescope = (
  args?: { view?: string | number } | [view: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: telescope.url(args, options),
  method: 'get',
})

telescope.definition = {
  methods: ['get', 'head'],
  url: '/telescope/{view?}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \Laravel\Telescope\Http\Controllers\HomeController::telescope
 * @see vendor/laravel/telescope/src/Http/Controllers/HomeController.php:15
 * @route '/telescope/{view?}'
 */
telescope.url = (
  args?: { view?: string | number } | [view: string | number] | string | number,
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { view: args }
  }

  if (Array.isArray(args)) {
    args = {
      view: args[0],
    }
  }

  args = applyUrlDefaults(args)

  validateParameters(args, ['view'])

  const parsedArgs = {
    view: args?.view,
  }

  return (
    telescope.definition.url.replace('{view?}', parsedArgs.view?.toString() ?? '').replace(/\/+$/, '') +
    queryParams(options)
  )
}

/**
 * @see \Laravel\Telescope\Http\Controllers\HomeController::telescope
 * @see vendor/laravel/telescope/src/Http/Controllers/HomeController.php:15
 * @route '/telescope/{view?}'
 */
telescope.get = (
  args?: { view?: string | number } | [view: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: telescope.url(args, options),
  method: 'get',
})

/**
 * @see \Laravel\Telescope\Http\Controllers\HomeController::telescope
 * @see vendor/laravel/telescope/src/Http/Controllers/HomeController.php:15
 * @route '/telescope/{view?}'
 */
telescope.head = (
  args?: { view?: string | number } | [view: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: telescope.url(args, options),
  method: 'head',
})
