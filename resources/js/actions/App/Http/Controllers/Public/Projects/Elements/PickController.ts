import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\Elements\PickController::store
 * @see app/Http/Controllers/Public/Projects/Elements/PickController.php:30
 * @route '/projects/{project}/elements/rounds/{elementRound}/pick'
 */
export const store = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/projects/{project}/elements/rounds/{elementRound}/pick',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\PickController::store
 * @see app/Http/Controllers/Public/Projects/Elements/PickController.php:30
 * @route '/projects/{project}/elements/rounds/{elementRound}/pick'
 */
store.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      elementRound: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    elementRound: typeof args.elementRound === 'object' ? args.elementRound.sqid : args.elementRound,
  }

  return (
    store.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{elementRound}', parsedArgs.elementRound.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\Elements\PickController::store
 * @see app/Http/Controllers/Public/Projects/Elements/PickController.php:30
 * @route '/projects/{project}/elements/rounds/{elementRound}/pick'
 */
store.post = (
  args:
    | {
        project: string | number | { sqid: string | number }
        elementRound: string | number | { sqid: string | number }
      }
    | [project: string | number | { sqid: string | number }, elementRound: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

const PickController = { store }

export default PickController
