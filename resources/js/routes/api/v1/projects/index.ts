import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
import shots from './shots'
/**
 * @see \App\Http\Controllers\Api\V1\ProjectController::index
 * @see app/Http/Controllers/Api/V1/ProjectController.php:18
 * @route '/api/v1/projects'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/api/v1/projects',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Api\V1\ProjectController::index
 * @see app/Http/Controllers/Api/V1/ProjectController.php:18
 * @route '/api/v1/projects'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Api\V1\ProjectController::index
 * @see app/Http/Controllers/Api/V1/ProjectController.php:18
 * @route '/api/v1/projects'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Api\V1\ProjectController::index
 * @see app/Http/Controllers/Api/V1/ProjectController.php:18
 * @route '/api/v1/projects'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

const projects = {
  index: Object.assign(index, index),
  shots: Object.assign(shots, shots),
}

export default projects
