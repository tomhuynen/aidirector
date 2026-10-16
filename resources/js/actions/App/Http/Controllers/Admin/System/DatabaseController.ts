import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::index
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:13
 * @route '/admin/system/database'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/admin/system/database',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::index
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:13
 * @route '/admin/system/database'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::index
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:13
 * @route '/admin/system/database'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::index
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:13
 * @route '/admin/system/database'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::download
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:21
 * @route '/admin/system/database/download/{name}'
 */
export const download = (
  args: { name: string | number } | [name: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: download.url(args, options),
  method: 'get',
})

download.definition = {
  methods: ['get', 'head'],
  url: '/admin/system/database/download/{name}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::download
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:21
 * @route '/admin/system/database/download/{name}'
 */
download.url = (
  args: { name: string | number } | [name: string | number] | string | number,
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { name: args }
  }

  if (Array.isArray(args)) {
    args = {
      name: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    name: args.name,
  }

  return (
    download.definition.url.replace('{name}', parsedArgs.name.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::download
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:21
 * @route '/admin/system/database/download/{name}'
 */
download.get = (
  args: { name: string | number } | [name: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: download.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Admin\System\DatabaseController::download
 * @see app/Http/Controllers/Admin/System/DatabaseController.php:21
 * @route '/admin/system/database/download/{name}'
 */
download.head = (
  args: { name: string | number } | [name: string | number] | string | number,
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: download.url(args, options),
  method: 'head',
})

const DatabaseController = { index, download }

export default DatabaseController
