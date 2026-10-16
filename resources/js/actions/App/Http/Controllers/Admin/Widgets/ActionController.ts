import { queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Admin\Widgets\ActionController::execute
 * @see app/Http/Controllers/Admin/Widgets/ActionController.php:12
 * @route '/admin/widgets/action/execute'
 */
export const execute = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: execute.url(options),
  method: 'post',
})

execute.definition = {
  methods: ['post'],
  url: '/admin/widgets/action/execute',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Admin\Widgets\ActionController::execute
 * @see app/Http/Controllers/Admin/Widgets/ActionController.php:12
 * @route '/admin/widgets/action/execute'
 */
execute.url = (options?: RouteQueryOptions) => {
  return execute.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Admin\Widgets\ActionController::execute
 * @see app/Http/Controllers/Admin/Widgets/ActionController.php:12
 * @route '/admin/widgets/action/execute'
 */
execute.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: execute.url(options),
  method: 'post',
})

const ActionController = { execute }

export default ActionController
