import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\Elements\UpdateController::store
 * @see app/Http/Controllers/Public/Projects/Elements/UpdateController.php:23
 * @route '/projects/{project}/elements/{element}'
 */
export const store = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/projects/{project}/elements/{element}',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\UpdateController::store
 * @see app/Http/Controllers/Public/Projects/Elements/UpdateController.php:23
 * @route '/projects/{project}/elements/{element}'
 */
store.url = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      element: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    element: typeof args.element === 'object' ? args.element.sqid : args.element,
  }

  return (
    store.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{element}', parsedArgs.element.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\UpdateController::store
 * @see app/Http/Controllers/Public/Projects/Elements/UpdateController.php:23
 * @route '/projects/{project}/elements/{element}'
 */
store.post = (
  args:
    | { project: string | number | { sqid: string | number }; element: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, element: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

const UpdateController = { store }

export default UpdateController
