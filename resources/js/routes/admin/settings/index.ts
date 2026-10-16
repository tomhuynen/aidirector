import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
import passkeys from './passkeys'
import password from './password'
import profile from './profile'
import security from './security'
/**
 * @see routes/admin.php:92
 * @route '/admin/settings/appearance'
 */
export const appearance = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: appearance.url(options),
  method: 'get',
})

appearance.definition = {
  methods: ['get', 'head'],
  url: '/admin/settings/appearance',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see routes/admin.php:92
 * @route '/admin/settings/appearance'
 */
appearance.url = (options?: RouteQueryOptions) => {
  return appearance.definition.url + queryParams(options)
}

/**
 * @see routes/admin.php:92
 * @route '/admin/settings/appearance'
 */
appearance.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: appearance.url(options),
  method: 'get',
})

/**
 * @see routes/admin.php:92
 * @route '/admin/settings/appearance'
 */
appearance.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: appearance.url(options),
  method: 'head',
})

const settings = {
  profile: Object.assign(profile, profile),
  password: Object.assign(password, password),
  security: Object.assign(security, security),
  passkeys: Object.assign(passkeys, passkeys),
  appearance: Object.assign(appearance, appearance),
}

export default settings
