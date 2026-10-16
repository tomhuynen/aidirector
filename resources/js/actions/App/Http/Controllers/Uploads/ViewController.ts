import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/uploads/{upload}'
 */
const view281b09a715df589184fb8bd4af59d913 = (
  args:
    | { upload: string | number | { sqid: string | number } }
    | [upload: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view281b09a715df589184fb8bd4af59d913.url(args, options),
  method: 'get',
})

view281b09a715df589184fb8bd4af59d913.definition = {
  methods: ['get', 'head'],
  url: '/uploads/{upload}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/uploads/{upload}'
 */
view281b09a715df589184fb8bd4af59d913.url = (
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
    view281b09a715df589184fb8bd4af59d913.definition.url
      .replace('{upload}', parsedArgs.upload.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/uploads/{upload}'
 */
view281b09a715df589184fb8bd4af59d913.get = (
  args:
    | { upload: string | number | { sqid: string | number } }
    | [upload: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view281b09a715df589184fb8bd4af59d913.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/uploads/{upload}'
 */
view281b09a715df589184fb8bd4af59d913.head = (
  args:
    | { upload: string | number | { sqid: string | number } }
    | [upload: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view281b09a715df589184fb8bd4af59d913.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/admin/uploads/{upload}'
 */
const view3ff2ce84b8a723a3a298276de3c90313 = (
  args:
    | { upload: string | number | { sqid: string | number } }
    | [upload: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view3ff2ce84b8a723a3a298276de3c90313.url(args, options),
  method: 'get',
})

view3ff2ce84b8a723a3a298276de3c90313.definition = {
  methods: ['get', 'head'],
  url: '/admin/uploads/{upload}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/admin/uploads/{upload}'
 */
view3ff2ce84b8a723a3a298276de3c90313.url = (
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
    view3ff2ce84b8a723a3a298276de3c90313.definition.url
      .replace('{upload}', parsedArgs.upload.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/admin/uploads/{upload}'
 */
view3ff2ce84b8a723a3a298276de3c90313.get = (
  args:
    | { upload: string | number | { sqid: string | number } }
    | [upload: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view3ff2ce84b8a723a3a298276de3c90313.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Uploads\ViewController::view
 * @see app/Http/Controllers/Uploads/ViewController.php:16
 * @route '/admin/uploads/{upload}'
 */
view3ff2ce84b8a723a3a298276de3c90313.head = (
  args:
    | { upload: string | number | { sqid: string | number } }
    | [upload: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view3ff2ce84b8a723a3a298276de3c90313.url(args, options),
  method: 'head',
})

/**
 * Multiple routes resolve to \App\Http\Controllers\Uploads\ViewController::view, so this export is a
 * dictionary keyed by URI rather than a callable. Call a specific route with `view['<uri>'](...)`,
 * or import the route by name from your generated `routes/` directory.
 */
export const view = {
  '/uploads/{upload}': view281b09a715df589184fb8bd4af59d913,
  '/admin/uploads/{upload}': view3ff2ce84b8a723a3a298276de3c90313,
}

const ViewController = { view }

export default ViewController
