import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
  validateParameters,
} from './../../wayfinder'
import view from './view'
/**
 * @see \InertiaUI\Table\Http\ActionController::__invoke
 * @see vendor/inertiaui/table/src/Http/ActionController.php:15
 * @route '/_inertia-tables/{table}/{name}/action/{action}/{state?}'
 */
export const action = (
  args:
    | { table: string | number; name: string | number; action: string | number; state?: string | number }
    | [table: string | number, name: string | number, action: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: action.url(args, options),
  method: 'post',
})

action.definition = {
  methods: ['post'],
  url: '/_inertia-tables/{table}/{name}/action/{action}/{state?}',
} satisfies RouteDefinition<['post']>

/**
 * @see \InertiaUI\Table\Http\ActionController::__invoke
 * @see vendor/inertiaui/table/src/Http/ActionController.php:15
 * @route '/_inertia-tables/{table}/{name}/action/{action}/{state?}'
 */
action.url = (
  args:
    | { table: string | number; name: string | number; action: string | number; state?: string | number }
    | [table: string | number, name: string | number, action: string | number, state: string | number],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      table: args[0],
      name: args[1],
      action: args[2],
      state: args[3],
    }
  }

  args = applyUrlDefaults(args)

  validateParameters(args, ['state'])

  const parsedArgs = {
    table: args.table,
    name: args.name,
    action: args.action,
    state: args.state,
  }

  return (
    action.definition.url
      .replace('{table}', parsedArgs.table.toString())
      .replace('{name}', parsedArgs.name.toString())
      .replace('{action}', parsedArgs.action.toString())
      .replace('{state?}', parsedArgs.state?.toString() ?? '')
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \InertiaUI\Table\Http\ActionController::__invoke
 * @see vendor/inertiaui/table/src/Http/ActionController.php:15
 * @route '/_inertia-tables/{table}/{name}/action/{action}/{state?}'
 */
action.post = (
  args:
    | { table: string | number; name: string | number; action: string | number; state?: string | number }
    | [table: string | number, name: string | number, action: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: action.url(args, options),
  method: 'post',
})

/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/export/{export}/{state?}'
 */
export const exportMethod = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: exportMethod.url(args, options),
  method: 'get',
})

exportMethod.definition = {
  methods: ['get', 'head'],
  url: '/_inertia-tables/{table}/{name}/export/{export}/{state?}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/export/{export}/{state?}'
 */
exportMethod.url = (
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
    exportMethod.definition.url
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
exportMethod.get = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: exportMethod.url(args, options),
  method: 'get',
})

/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/export/{export}/{state?}'
 */
exportMethod.head = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: exportMethod.url(args, options),
  method: 'head',
})

/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/async-export/{export}/{state?}'
 */
export const asyncExport = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: asyncExport.url(args, options),
  method: 'post',
})

asyncExport.definition = {
  methods: ['post'],
  url: '/_inertia-tables/{table}/{name}/async-export/{export}/{state?}',
} satisfies RouteDefinition<['post']>

/**
 * @see \InertiaUI\Table\Http\ExportController::__invoke
 * @see vendor/inertiaui/table/src/Http/ExportController.php:21
 * @route '/_inertia-tables/{table}/{name}/async-export/{export}/{state?}'
 */
asyncExport.url = (
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
    asyncExport.definition.url
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
asyncExport.post = (
  args:
    | { table: string | number; name: string | number; export: string | number; state?: string | number }
    | [table: string | number, name: string | number, exportParam: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: asyncExport.url(args, options),
  method: 'post',
})

const inertiaTables = {
  action: Object.assign(action, action),
  export: Object.assign(exportMethod, exportMethod),
  asyncExport: Object.assign(asyncExport, asyncExport),
  view: Object.assign(view, view),
}

export default inertiaTables
