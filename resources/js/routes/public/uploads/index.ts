import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
/**
 * @see \App\Http\Controllers\Uploads\StoreController::store
 * @see app/Http/Controllers/Uploads/StoreController.php:13
 * @route '/uploads'
 */
export const store = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/uploads',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Uploads\StoreController::store
 * @see app/Http/Controllers/Uploads/StoreController.php:13
 * @route '/uploads'
 */
store.url = (options?: RouteQueryOptions) => {
  return store.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Uploads\StoreController::store
 * @see app/Http/Controllers/Uploads/StoreController.php:13
 * @route '/uploads'
 */
store.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: store.url(options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/uploads/{upload}'
 */
export const view = (
  args:
    | { upload: string | number | { sqid: string | number } }
    | [upload: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/uploads/{upload}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/uploads/{upload}'
 */
view.url = (
  args:
    | { upload: string | number | { sqid: string | number } }
    | [upload: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { upload: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { upload: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      upload: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    upload: typeof args.upload === 'object' ? args.upload.sqid : args.upload,
  }

  return (
    view.definition.url.replace('{upload}', parsedArgs.upload.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/uploads/{upload}'
 */
view.get = (
  args:
    | { upload: string | number | { sqid: string | number } }
    | [upload: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/uploads/{upload}'
 */
view.head = (
  args:
    | { upload: string | number | { sqid: string | number } }
    | [upload: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

const uploads = {
  store: Object.assign(store, store),
  view: Object.assign(view, view),
}

export default uploads
