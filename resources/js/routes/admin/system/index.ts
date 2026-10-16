import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
import database547c02 from './database'
/**
 * @see \App\Http\Controllers\Admin\System\ServerController::server
 * @see app/Http/Controllers/Admin/System/ServerController.php:21
 * @route '/admin/system/server'
 */
export const server = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: server.url(options),
  method: 'get',
})

server.definition = {
  methods: ['get', 'head'],
  url: '/admin/system/server',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\System\ServerController::server
 * @see app/Http/Controllers/Admin/System/ServerController.php:21
 * @route '/admin/system/server'
 */
server.url = (options?: RouteQueryOptions) => {
  return server.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\System\ServerController::server
 * @see app/Http/Controllers/Admin/System/ServerController.php:21
 * @route '/admin/system/server'
 */
server.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: server.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\System\ServerController::server
 * @see app/Http/Controllers/Admin/System/ServerController.php:21
 * @route '/admin/system/server'
 */
server.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: server.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::database
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:13
 * @route '/admin/system/database'
 */
export const database = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: database.url(options),
  method: 'get',
})

database.definition = {
  methods: ['get', 'head'],
  url: '/admin/system/database',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::database
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:13
 * @route '/admin/system/database'
 */
database.url = (options?: RouteQueryOptions) => {
  return database.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::database
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:13
 * @route '/admin/system/database'
 */
database.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: database.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::database
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:13
 * @route '/admin/system/database'
 */
database.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: database.url(options),
  method: 'head',
})

const system = {
  server: Object.assign(server, server),
  database: Object.assign(database, database547c02),
}

export default system
