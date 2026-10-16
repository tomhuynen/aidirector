import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::dismissAll
 * @see app/Http/Controllers/Public/Shots/IssueController.php:54
 * @route '/projects/{project}/shots/{shot}/issues/dismiss-all'
 */
export const dismissAll = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: dismissAll.url(args, options),
  method: 'post',
})

dismissAll.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/issues/dismiss-all',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::dismissAll
 * @see app/Http/Controllers/Public/Shots/IssueController.php:54
 * @route '/projects/{project}/shots/{shot}/issues/dismiss-all'
 */
dismissAll.url = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
  }

  return (
    dismissAll.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::dismissAll
 * @see app/Http/Controllers/Public/Shots/IssueController.php:54
 * @route '/projects/{project}/shots/{shot}/issues/dismiss-all'
 */
dismissAll.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: dismissAll.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::fixAll
 * @see app/Http/Controllers/Public/Shots/IssueController.php:42
 * @route '/projects/{project}/shots/{shot}/issues/fix-all'
 */
export const fixAll = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: fixAll.url(args, options),
  method: 'post',
})

fixAll.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/issues/fix-all',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::fixAll
 * @see app/Http/Controllers/Public/Shots/IssueController.php:42
 * @route '/projects/{project}/shots/{shot}/issues/fix-all'
 */
fixAll.url = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
  }

  return (
    fixAll.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::fixAll
 * @see app/Http/Controllers/Public/Shots/IssueController.php:42
 * @route '/projects/{project}/shots/{shot}/issues/fix-all'
 */
fixAll.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: fixAll.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::fix
 * @see app/Http/Controllers/Public/Shots/IssueController.php:18
 * @route '/projects/{project}/shots/{shot}/issues/{group}/fix'
 */
export const fix = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        group: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        group: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: fix.url(args, options),
  method: 'post',
})

fix.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/issues/{group}/fix',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::fix
 * @see app/Http/Controllers/Public/Shots/IssueController.php:18
 * @route '/projects/{project}/shots/{shot}/issues/{group}/fix'
 */
fix.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        group: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        group: string | number,
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      group: args[2],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    group: args.group,
  }

  return (
    fix.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{group}', parsedArgs.group.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::fix
 * @see app/Http/Controllers/Public/Shots/IssueController.php:18
 * @route '/projects/{project}/shots/{shot}/issues/{group}/fix'
 */
fix.post = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        group: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        group: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: fix.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::redraw
 * @see app/Http/Controllers/Public/Shots/IssueController.php:30
 * @route '/projects/{project}/shots/{shot}/issues/{group}/redraw'
 */
export const redraw = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        group: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        group: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: redraw.url(args, options),
  method: 'post',
})

redraw.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/issues/{group}/redraw',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::redraw
 * @see app/Http/Controllers/Public/Shots/IssueController.php:30
 * @route '/projects/{project}/shots/{shot}/issues/{group}/redraw'
 */
redraw.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        group: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        group: string | number,
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      group: args[2],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    group: args.group,
  }

  return (
    redraw.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{group}', parsedArgs.group.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::redraw
 * @see app/Http/Controllers/Public/Shots/IssueController.php:30
 * @route '/projects/{project}/shots/{shot}/issues/{group}/redraw'
 */
redraw.post = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        group: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        group: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: redraw.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::dismiss
 * @see app/Http/Controllers/Public/Shots/IssueController.php:66
 * @route '/projects/{project}/shots/{shot}/issues/{group}/dismiss'
 */
export const dismiss = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        group: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        group: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: dismiss.url(args, options),
  method: 'post',
})

dismiss.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/issues/{group}/dismiss',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::dismiss
 * @see app/Http/Controllers/Public/Shots/IssueController.php:66
 * @route '/projects/{project}/shots/{shot}/issues/{group}/dismiss'
 */
dismiss.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        group: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        group: string | number,
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      group: args[2],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    group: args.group,
  }

  return (
    dismiss.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{group}', parsedArgs.group.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\IssueController::dismiss
 * @see app/Http/Controllers/Public/Shots/IssueController.php:66
 * @route '/projects/{project}/shots/{shot}/issues/{group}/dismiss'
 */
dismiss.post = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        group: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        group: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: dismiss.url(args, options),
  method: 'post',
})

const IssueController = { dismissAll, fixAll, fix, redraw, dismiss }

export default IssueController
