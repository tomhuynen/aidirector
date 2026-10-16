import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
  validateParameters,
} from './../../../../wayfinder'
/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/export/{export}/{state?}'
 */
const ExportController8c3580db2a3613575c21e2127a2da0c5 = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: ExportController8c3580db2a3613575c21e2127a2da0c5.url(args, options),
  method: 'get',
})

ExportController8c3580db2a3613575c21e2127a2da0c5.definition = {
  methods: ['get', 'head'],
  url: '/_inertia-tables/{table}/{name}/export/{export}/{state?}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/export/{export}/{state?}'
 */
ExportController8c3580db2a3613575c21e2127a2da0c5.url = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      table: args[0],
      name: args[1],
      export: args[2],
      state: args[3],
    }
  }

  args = applyUrlDefaults(args)

  validateParameters(args, ['state'])

  const parsedArgs = {
    table: args.table,
    name: args.name,
    export: args.export,
    state: args.state,
  }

  return (
    ExportController8c3580db2a3613575c21e2127a2da0c5.definition.url
      .replace('{table}', parsedArgs.table.toString())
      .replace('{name}', parsedArgs.name.toString())
      .replace('{export}', parsedArgs.export.toString())
      .replace('{state?}', parsedArgs.state?.toString() ?? '')
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/export/{export}/{state?}'
 */
ExportController8c3580db2a3613575c21e2127a2da0c5.get = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: ExportController8c3580db2a3613575c21e2127a2da0c5.url(args, options),
  method: 'get',
})

/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/export/{export}/{state?}'
 */
ExportController8c3580db2a3613575c21e2127a2da0c5.head = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: ExportController8c3580db2a3613575c21e2127a2da0c5.url(args, options),
  method: 'head',
})

/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/async-export/{export}/{state?}'
 */
const ExportController533a50d30cc200337631b68fb6eb99c1 = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: ExportController533a50d30cc200337631b68fb6eb99c1.url(args, options),
  method: 'post',
})

ExportController533a50d30cc200337631b68fb6eb99c1.definition = {
  methods: ['post'],
  url: '/_inertia-tables/{table}/{name}/async-export/{export}/{state?}',
} satisfies RouteDefinition<['post']>

/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/async-export/{export}/{state?}'
 */
ExportController533a50d30cc200337631b68fb6eb99c1.url = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      table: args[0],
      name: args[1],
      export: args[2],
      state: args[3],
    }
  }

  args = applyUrlDefaults(args)

  validateParameters(args, ['state'])

  const parsedArgs = {
    table: args.table,
    name: args.name,
    export: args.export,
    state: args.state,
  }

  return (
    ExportController533a50d30cc200337631b68fb6eb99c1.definition.url
      .replace('{table}', parsedArgs.table.toString())
      .replace('{name}', parsedArgs.name.toString())
      .replace('{export}', parsedArgs.export.toString())
      .replace('{state?}', parsedArgs.state?.toString() ?? '')
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/async-export/{export}/{state?}'
 */
ExportController533a50d30cc200337631b68fb6eb99c1.post = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: ExportController533a50d30cc200337631b68fb6eb99c1.url(args, options),
  method: 'post',
})

/**
 * Multiple routes resolve to \InertiaUI\Table\Http\ExportController::ExportController, so this export is a
 * dictionary keyed by URI rather than a callable. Call a specific route with `ExportController['<uri>'](...)`,
 * or import the route by name from your generated `routes/` directory.
 */
const ExportController = {
  '/_inertia-tables/{table}/{name}/export/{export}/{state?}': ExportController8c3580db2a3613575c21e2127a2da0c5,
  '/_inertia-tables/{table}/{name}/async-export/{export}/{state?}': ExportController533a50d30cc200337631b68fb6eb99c1,
}

export default ExportController
