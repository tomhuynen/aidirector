import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../wayfinder'
/**
 * @see \Spatie\LaravelPasskeys\Http\Controllers\GeneratePasskeyAuthenticationOptionsController::__invoke
 * @see vendor/spatie/laravel-passkeys/src/Http/Controllers/GeneratePasskeyAuthenticationOptionsController.php:11
 * @route '/auth/passkeys/options/auth'
 */
const GeneratePasskeyAuthenticationOptionsController = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: GeneratePasskeyAuthenticationOptionsController.url(options),
  method: 'get',
})

GeneratePasskeyAuthenticationOptionsController.definition = {
  methods: ['get', 'head'],
  url: '/auth/passkeys/options/auth',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \Spatie\LaravelPasskeys\Http\Controllers\GeneratePasskeyAuthenticationOptionsController::__invoke
 * @see vendor/spatie/laravel-passkeys/src/Http/Controllers/GeneratePasskeyAuthenticationOptionsController.php:11
 * @route '/auth/passkeys/options/auth'
 */
GeneratePasskeyAuthenticationOptionsController.url = (options?: RouteQueryOptions) => {
  return GeneratePasskeyAuthenticationOptionsController.definition.url + queryParams(options)
}

/**
 * @see \Spatie\LaravelPasskeys\Http\Controllers\GeneratePasskeyAuthenticationOptionsController::__invoke
 * @see vendor/spatie/laravel-passkeys/src/Http/Controllers/GeneratePasskeyAuthenticationOptionsController.php:11
 * @route '/auth/passkeys/options/auth'
 */
GeneratePasskeyAuthenticationOptionsController.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: GeneratePasskeyAuthenticationOptionsController.url(options),
  method: 'get',
})

/**
 * @see \Spatie\LaravelPasskeys\Http\Controllers\GeneratePasskeyAuthenticationOptionsController::__invoke
 * @see vendor/spatie/laravel-passkeys/src/Http/Controllers/GeneratePasskeyAuthenticationOptionsController.php:11
 * @route '/auth/passkeys/options/auth'
 */
GeneratePasskeyAuthenticationOptionsController.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: GeneratePasskeyAuthenticationOptionsController.url(options),
  method: 'head',
})

export default GeneratePasskeyAuthenticationOptionsController
