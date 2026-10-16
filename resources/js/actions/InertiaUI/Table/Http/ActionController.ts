import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
  validateParameters,
} from './../../../../wayfinder'
/**
 * @see \InertiaUI\Table\Http\ActionController::__invoke
 * @see vendor/inertiaui/table/src/Http/ActionController.php:15
 * @route '/_inertia-tables/{table}/{name}/action/{action}/{state?}'
 */
const ActionController = (
  args:
    | { table: string | number; name: string | number; action: string | number; state?: string | number }
    | [table: string | number, name: string | number, action: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: ActionController.url(args, options),
  method: 'post',
})

ActionController.definition = {
  methods: ['post'],
  url: '/_inertia-tables/{table}/{name}/action/{action}/{state?}',
} satisfies RouteDefinition<['post']>

/**
 * @see \InertiaUI\Table\Http\ActionController::__invoke
 * @see vendor/inertiaui/table/src/Http/ActionController.php:15
 * @route '/_inertia-tables/{table}/{name}/action/{action}/{state?}'
 */
ActionController.url = (
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
    ActionController.definition.url
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
ActionController.post = (
  args:
    | { table: string | number; name: string | number; action: string | number; state?: string | number }
    | [table: string | number, name: string | number, action: string | number, state: string | number],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: ActionController.url(args, options),
  method: 'post',
})

export default ActionController
