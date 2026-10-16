import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Api\Auth\TokenController::create
 * @see app/Http/Controllers/Api/Auth/TokenController.php:14
 * @route '/api/auth/token'
 */
export const create = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: create.url(options),
  method: 'post',
})

create.definition = {
  methods: ['post'],
  url: '/api/auth/token',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Api\Auth\TokenController::create
 * @see app/Http/Controllers/Api/Auth/TokenController.php:14
 * @route '/api/auth/token'
 */
create.url = (options?: RouteQueryOptions) => {
  return create.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Api\Auth\TokenController::create
 * @see app/Http/Controllers/Api/Auth/TokenController.php:14
 * @route '/api/auth/token'
 */
create.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: create.url(options),
  method: 'post',
})

const TokenController = { create }

export default TokenController
