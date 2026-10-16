import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
  validateParameters,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Media\ViewController::view
 * @see app/Http/Controllers/Public/Media/ViewController.php:29
 * @route '/media/{media}/{conversion?}'
 */
export const view = (
  args:
    | { media: string | number | { sqid: string | number }; conversion?: string | number }
    | [media: string | number | { sqid: string | number }, conversion: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/media/{media}/{conversion?}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Media\ViewController::view
 * @see app/Http/Controllers/Public/Media/ViewController.php:29
 * @route '/media/{media}/{conversion?}'
 */
view.url = (
  args:
    | { media: string | number | { sqid: string | number }; conversion?: string | number }
    | [media: string | number | { sqid: string | number }, conversion: string | number],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      media: args[0],
      conversion: args[1],
    }
  }

  args = applyUrlDefaults(args)

  validateParameters(args, ['conversion'])

  const parsedArgs = {
    media: typeof args.media === 'object' ? args.media.sqid : args.media,
    conversion: args.conversion,
  }

  return (
    view.definition.url
      .replace('{media}', parsedArgs.media.toString())
      .replace('{conversion?}', parsedArgs.conversion?.toString() ?? '')
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Media\ViewController::view
 * @see app/Http/Controllers/Public/Media/ViewController.php:29
 * @route '/media/{media}/{conversion?}'
 */
view.get = (
  args:
    | { media: string | number | { sqid: string | number }; conversion?: string | number }
    | [media: string | number | { sqid: string | number }, conversion: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Media\ViewController::view
 * @see app/Http/Controllers/Public/Media/ViewController.php:29
 * @route '/media/{media}/{conversion?}'
 */
view.head = (
  args:
    | { media: string | number | { sqid: string | number }; conversion?: string | number }
    | [media: string | number | { sqid: string | number }, conversion: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

const ViewController = { view }

export default ViewController
