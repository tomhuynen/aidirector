import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Projects\RuleController::accept
 * @see app/Http/Controllers/Public/Projects/RuleController.php:19
 * @route '/projects/{project}/rules/{rule}/accept'
 */
export const accept = (
  args:
    | { project: string | number | { sqid: string | number }; rule: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, rule: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: accept.url(args, options),
  method: 'post',
})

accept.definition = {
  methods: ['post'],
  url: '/projects/{project}/rules/{rule}/accept',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\RuleController::accept
 * @see app/Http/Controllers/Public/Projects/RuleController.php:19
 * @route '/projects/{project}/rules/{rule}/accept'
 */
accept.url = (
  args:
    | { project: string | number | { sqid: string | number }; rule: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, rule: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      rule: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    rule: typeof args.rule === 'object' ? args.rule.sqid : args.rule,
  }

  return (
    accept.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{rule}', parsedArgs.rule.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\RuleController::accept
 * @see app/Http/Controllers/Public/Projects/RuleController.php:19
 * @route '/projects/{project}/rules/{rule}/accept'
 */
accept.post = (
  args:
    | { project: string | number | { sqid: string | number }; rule: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, rule: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: accept.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Projects\RuleController::dismiss
 * @see app/Http/Controllers/Public/Projects/RuleController.php:31
 * @route '/projects/{project}/rules/{rule}/dismiss'
 */
export const dismiss = (
  args:
    | { project: string | number | { sqid: string | number }; rule: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, rule: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: dismiss.url(args, options),
  method: 'post',
})

dismiss.definition = {
  methods: ['post'],
  url: '/projects/{project}/rules/{rule}/dismiss',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\RuleController::dismiss
 * @see app/Http/Controllers/Public/Projects/RuleController.php:31
 * @route '/projects/{project}/rules/{rule}/dismiss'
 */
dismiss.url = (
  args:
    | { project: string | number | { sqid: string | number }; rule: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, rule: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      rule: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    rule: typeof args.rule === 'object' ? args.rule.sqid : args.rule,
  }

  return (
    dismiss.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{rule}', parsedArgs.rule.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\RuleController::dismiss
 * @see app/Http/Controllers/Public/Projects/RuleController.php:31
 * @route '/projects/{project}/rules/{rule}/dismiss'
 */
dismiss.post = (
  args:
    | { project: string | number | { sqid: string | number }; rule: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, rule: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: dismiss.url(args, options),
  method: 'post',
})

const RuleController = { accept, dismiss }

export default RuleController
