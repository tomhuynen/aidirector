import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Uploads\StoreController::store
 * @see app/Http/Controllers/Uploads/StoreController.php:13
 * @route '/uploads'
 */
const store04e8b22e5c92a5d4054a9aa6ad0e0bb9 = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store04e8b22e5c92a5d4054a9aa6ad0e0bb9.url(options),
  method: 'post',
})

store04e8b22e5c92a5d4054a9aa6ad0e0bb9.definition = {
  methods: ['post'],
  url: '/uploads',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Uploads\StoreController::store
 * @see app/Http/Controllers/Uploads/StoreController.php:13
 * @route '/uploads'
 */
store04e8b22e5c92a5d4054a9aa6ad0e0bb9.url = (options?: RouteQueryOptions) => {
  return store04e8b22e5c92a5d4054a9aa6ad0e0bb9.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Uploads\StoreController::store
 * @see app/Http/Controllers/Uploads/StoreController.php:13
 * @route '/uploads'
 */
store04e8b22e5c92a5d4054a9aa6ad0e0bb9.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store04e8b22e5c92a5d4054a9aa6ad0e0bb9.url(options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Uploads\StoreController::store
 * @see app/Http/Controllers/Uploads/StoreController.php:13
 * @route '/admin/uploads'
 */
const storeb83090f26dc0d388a2acec156008a39c = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: storeb83090f26dc0d388a2acec156008a39c.url(options),
  method: 'post',
})

storeb83090f26dc0d388a2acec156008a39c.definition = {
  methods: ['post'],
  url: '/admin/uploads',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Uploads\StoreController::store
 * @see app/Http/Controllers/Uploads/StoreController.php:13
 * @route '/admin/uploads'
 */
storeb83090f26dc0d388a2acec156008a39c.url = (options?: RouteQueryOptions) => {
  return storeb83090f26dc0d388a2acec156008a39c.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Uploads\StoreController::store
 * @see app/Http/Controllers/Uploads/StoreController.php:13
 * @route '/admin/uploads'
 */
storeb83090f26dc0d388a2acec156008a39c.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: storeb83090f26dc0d388a2acec156008a39c.url(options),
  method: 'post',
})

/**
 * Multiple routes resolve to \App\Http\Controllers\Uploads\StoreController::store, so this export is a
 * dictionary keyed by URI rather than a callable. Call a specific route with `store['<uri>'](...)`,
 * or import the route by name from your generated `routes/` directory.
 */
export const store = {
  '/uploads': store04e8b22e5c92a5d4054a9aa6ad0e0bb9,
  '/admin/uploads': storeb83090f26dc0d388a2acec156008a39c,
}

const StoreController = { store }

export default StoreController
