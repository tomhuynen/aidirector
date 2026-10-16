import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../wayfinder'
import auth from './auth'
import media from './media'
import projects from './projects'
import shots from './shots'
import uploads from './uploads'
/**
 * @see \App\Http\Controllers\HomeController::home
 * @see app/Http/Controllers/HomeController.php:11
 * @route '/'
 */
export const home = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: home.url(options),
  method: 'get',
})

home.definition = {
  methods: ['get', 'head'],
  url: '/',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\HomeController::home
 * @see app/Http/Controllers/HomeController.php:11
 * @route '/'
 */
home.url = (options?: RouteQueryOptions) => {
  return home.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\HomeController::home
 * @see app/Http/Controllers/HomeController.php:11
 * @route '/'
 */
home.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: home.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\HomeController::home
 * @see app/Http/Controllers/HomeController.php:11
 * @route '/'
 */
home.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: home.url(options),
  method: 'head',
})

const publicMethod = {
  home: Object.assign(home, home),
  auth: Object.assign(auth, auth),
  projects: Object.assign(projects, projects),
  media: Object.assign(media, media),
  shots: Object.assign(shots, shots),
  uploads: Object.assign(uploads, uploads),
}

export default publicMethod
