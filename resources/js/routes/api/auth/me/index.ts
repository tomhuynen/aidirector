import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Api\Auth\MeController::view
 * @see app/Http/Controllers/Api/Auth/MeController.php:12
 * @route '/api/auth/me'
 */
export const view = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/api/auth/me',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Api\Auth\MeController::view
 * @see app/Http/Controllers/Api/Auth/MeController.php:12
 * @route '/api/auth/me'
 */
view.url = (options?: RouteQueryOptions) => {
  return view.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Api\Auth\MeController::view
 * @see app/Http/Controllers/Api/Auth/MeController.php:12
 * @route '/api/auth/me'
 */
view.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: view.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Api\Auth\MeController::view
 * @see app/Http/Controllers/Api/Auth/MeController.php:12
 * @route '/api/auth/me'
 */
view.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: view.url(options),
  method: 'head',
})

const me = {
  view: Object.assign(view, view),
}

export default me
