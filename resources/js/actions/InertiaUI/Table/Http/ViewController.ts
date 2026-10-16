import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
  validateParameters,
} from './../../../../wayfinder'
/**
 * @see \InertiaUI\Table\Http\ViewController::store
 * @see vendor/inertiaui/table/src/Http/ViewController.php:14
 * @route '/_inertia-tables/{table}/{name}/view/{state?}'
 */
export const store = (
  args:
    | { table: string | number; name: string | number; state?: string | number }
    | [table: string | number, name: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/_inertia-tables/{table}/{name}/view/{state?}',
} satisfies RouteDefinition<['post']>

/**
 * @see \InertiaUI\Table\Http\ViewController::store
 * @see vendor/inertiaui/table/src/Http/ViewController.php:14
 * @route '/_inertia-tables/{table}/{name}/view/{state?}'
 */
store.url = (
  args:
    | { table: string | number; name: string | number; state?: string | number }
    | [table: string | number, name: string | number, state: string | number],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      table: args[0],
      name: args[1],
      state: args[2],
    }
  }

  args = applyUrlDefaults(args)

  validateParameters(args, ['state'])

  const parsedArgs = {
    table: args.table,
    name: args.name,
    state: args.state,
  }

  return (
    store.definition.url
      .replace('{table}', parsedArgs.table.toString())
      .replace('{name}', parsedArgs.name.toString())
      .replace('{state?}', parsedArgs.state?.toString() ?? '')
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \InertiaUI\Table\Http\ViewController::store
 * @see vendor/inertiaui/table/src/Http/ViewController.php:14
 * @route '/_inertia-tables/{table}/{name}/view/{state?}'
 */
store.post = (
  args:
    | { table: string | number; name: string | number; state?: string | number }
    | [table: string | number, name: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

/**
 * @see \InertiaUI\Table\Http\ViewController::destroy
 * @see vendor/inertiaui/table/src/Http/ViewController.php:30
 * @route '/_inertia-tables/{table}/{name}/view/{key}/{state?}'
 */
export const destroy = (
  args:
    | { table: string | number; name: string | number; key: string | number; state?: string | number }
    | [table: string | number, name: string | number, key: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

destroy.definition = {
  methods: ['delete'],
  url: '/_inertia-tables/{table}/{name}/view/{key}/{state?}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \InertiaUI\Table\Http\ViewController::destroy
 * @see vendor/inertiaui/table/src/Http/ViewController.php:30
 * @route '/_inertia-tables/{table}/{name}/view/{key}/{state?}'
 */
destroy.url = (
  args:
    | { table: string | number; name: string | number; key: string | number; state?: string | number }
    | [table: string | number, name: string | number, key: string | number, state: string | number],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      table: args[0],
      name: args[1],
      key: args[2],
      state: args[3],
    }
  }

  args = applyUrlDefaults(args)

  validateParameters(args, ['state'])

  const parsedArgs = {
    table: args.table,
    name: args.name,
    key: args.key,
    state: args.state,
  }

  return (
    destroy.definition.url
      .replace('{table}', parsedArgs.table.toString())
      .replace('{name}', parsedArgs.name.toString())
      .replace('{key}', parsedArgs.key.toString())
      .replace('{state?}', parsedArgs.state?.toString() ?? '')
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \InertiaUI\Table\Http\ViewController::destroy
 * @see vendor/inertiaui/table/src/Http/ViewController.php:30
 * @route '/_inertia-tables/{table}/{name}/view/{key}/{state?}'
 */
destroy.delete = (
  args:
    | { table: string | number; name: string | number; key: string | number; state?: string | number }
    | [table: string | number, name: string | number, key: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

const ViewController = { store, destroy }

export default ViewController
