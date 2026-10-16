import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
import issues from './issues'
import keyframes from './keyframes'
import merge518753 from './merge'
import plan from './plan'
import video from './video'
import voiceOver004910 from './voice-over'
/**
 * @see \App\Http\Controllers\Public\Shots\CreateController::create
 * @see app/Http/Controllers/Public/Shots/CreateController.php:27
 * @route '/projects/{project}/shots/create'
 */
export const create = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: create.url(args, options),
  method: 'get',
})

create.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/shots/create',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Shots\CreateController::create
 * @see app/Http/Controllers/Public/Shots/CreateController.php:27
 * @route '/projects/{project}/shots/create'
 */
create.url = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { project: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { project: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      project: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
  }

  return (
    create.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\CreateController::create
 * @see app/Http/Controllers/Public/Shots/CreateController.php:27
 * @route '/projects/{project}/shots/create'
 */
create.get = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: create.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Shots\CreateController::create
 * @see app/Http/Controllers/Public/Shots/CreateController.php:27
 * @route '/projects/{project}/shots/create'
 */
create.head = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: create.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Shots\CreateController::store
 * @see app/Http/Controllers/Public/Shots/CreateController.php:39
 * @route '/projects/{project}/shots/create'
 */
export const store = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/create',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\CreateController::store
 * @see app/Http/Controllers/Public/Shots/CreateController.php:39
 * @route '/projects/{project}/shots/create'
 */
store.url = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { project: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { project: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      project: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
  }

  return (
    store.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\CreateController::store
 * @see app/Http/Controllers/Public/Shots/CreateController.php:39
 * @route '/projects/{project}/shots/create'
 */
store.post = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\ReorderController::reorder
 * @see app/Http/Controllers/Public/Shots/ReorderController.php:16
 * @route '/projects/{project}/shots/reorder'
 */
export const reorder = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: reorder.url(args, options),
  method: 'post',
})

reorder.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/reorder',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\ReorderController::reorder
 * @see app/Http/Controllers/Public/Shots/ReorderController.php:16
 * @route '/projects/{project}/shots/reorder'
 */
reorder.url = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { project: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { project: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      project: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
  }

  return (
    reorder.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') +
    queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\ReorderController::reorder
 * @see app/Http/Controllers/Public/Shots/ReorderController.php:16
 * @route '/projects/{project}/shots/reorder'
 */
reorder.post = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: reorder.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\MergeController::merge
 * @see app/Http/Controllers/Public/Shots/MergeController.php:24
 * @route '/projects/{project}/shots/merge'
 */
export const merge = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: merge.url(args, options),
  method: 'post',
})

merge.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/merge',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\MergeController::merge
 * @see app/Http/Controllers/Public/Shots/MergeController.php:24
 * @route '/projects/{project}/shots/merge'
 */
merge.url = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
) => {
  if (typeof args === 'string' || typeof args === 'number') {
    args = { project: args }
  }

  if (typeof args === 'object' && !Array.isArray(args) && 'sqid' in args) {
    args = { project: args.sqid }
  }

  if (Array.isArray(args)) {
    args = {
      project: args[0],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
  }

  return (
    merge.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\MergeController::merge
 * @see app/Http/Controllers/Public/Shots/MergeController.php:24
 * @route '/projects/{project}/shots/merge'
 */
merge.post = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: merge.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\MergeController::unmerge
 * @see app/Http/Controllers/Public/Shots/MergeController.php:54
 * @route '/projects/{project}/shots/{shot}/merge'
 */
export const unmerge = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: unmerge.url(args, options),
  method: 'delete',
})

unmerge.definition = {
  methods: ['delete'],
  url: '/projects/{project}/shots/{shot}/merge',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Shots\MergeController::unmerge
 * @see app/Http/Controllers/Public/Shots/MergeController.php:54
 * @route '/projects/{project}/shots/{shot}/merge'
 */
unmerge.url = (
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
    unmerge.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\MergeController::unmerge
 * @see app/Http/Controllers/Public/Shots/MergeController.php:54
 * @route '/projects/{project}/shots/{shot}/merge'
 */
unmerge.delete = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: unmerge.url(args, options),
  method: 'delete',
})

/**
 * @see \App\Http\Controllers\Public\Shots\ViewController::view
 * @see app/Http/Controllers/Public/Shots/ViewController.php:23
 * @route '/projects/{project}/shots/{shot}'
 */
export const view = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/shots/{shot}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Shots\ViewController::view
 * @see app/Http/Controllers/Public/Shots/ViewController.php:23
 * @route '/projects/{project}/shots/{shot}'
 */
view.url = (
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
    view.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\ViewController::view
 * @see app/Http/Controllers/Public/Shots/ViewController.php:23
 * @route '/projects/{project}/shots/{shot}'
 */
view.get = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Shots\ViewController::view
 * @see app/Http/Controllers/Public/Shots/ViewController.php:23
 * @route '/projects/{project}/shots/{shot}'
 */
view.head = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Shots\RetryController::retry
 * @see app/Http/Controllers/Public/Shots/RetryController.php:24
 * @route '/projects/{project}/shots/{shot}/retry'
 */
export const retry = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: retry.url(args, options),
  method: 'post',
})

retry.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/retry',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\RetryController::retry
 * @see app/Http/Controllers/Public/Shots/RetryController.php:24
 * @route '/projects/{project}/shots/{shot}/retry'
 */
retry.url = (
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
    retry.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\RetryController::retry
 * @see app/Http/Controllers/Public/Shots/RetryController.php:24
 * @route '/projects/{project}/shots/{shot}/retry'
 */
retry.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: retry.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\DurationController::duration
 * @see app/Http/Controllers/Public/Shots/DurationController.php:25
 * @route '/projects/{project}/shots/{shot}/duration'
 */
export const duration = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: duration.url(args, options),
  method: 'post',
})

duration.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/duration',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\DurationController::duration
 * @see app/Http/Controllers/Public/Shots/DurationController.php:25
 * @route '/projects/{project}/shots/{shot}/duration'
 */
duration.url = (
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
    duration.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\DurationController::duration
 * @see app/Http/Controllers/Public/Shots/DurationController.php:25
 * @route '/projects/{project}/shots/{shot}/duration'
 */
duration.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: duration.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\VoiceOverController::voiceOver
 * @see app/Http/Controllers/Public/Shots/VoiceOverController.php:19
 * @route '/projects/{project}/shots/{shot}/voice-over'
 */
export const voiceOver = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: voiceOver.url(args, options),
  method: 'post',
})

voiceOver.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/voice-over',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\VoiceOverController::voiceOver
 * @see app/Http/Controllers/Public/Shots/VoiceOverController.php:19
 * @route '/projects/{project}/shots/{shot}/voice-over'
 */
voiceOver.url = (
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
    voiceOver.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\VoiceOverController::voiceOver
 * @see app/Http/Controllers/Public/Shots/VoiceOverController.php:19
 * @route '/projects/{project}/shots/{shot}/voice-over'
 */
voiceOver.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: voiceOver.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:22
 * @route '/projects/{project}/shots/{shot}'
 */
export const destroy = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

destroy.definition = {
  methods: ['delete'],
  url: '/projects/{project}/shots/{shot}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:22
 * @route '/projects/{project}/shots/{shot}'
 */
destroy.url = (
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
    destroy.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/DestroyController.php:22
 * @route '/projects/{project}/shots/{shot}'
 */
destroy.delete = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

const shots = {
  create: Object.assign(create, create),
  store: Object.assign(store, store),
  reorder: Object.assign(reorder, reorder),
  destroy: Object.assign(destroy, destroy),
  merge: Object.assign(merge, merge518753),
  unmerge: Object.assign(unmerge, unmerge),
  view: Object.assign(view, view),
  plan: Object.assign(plan, plan),
  keyframes: Object.assign(keyframes, keyframes),
  issues: Object.assign(issues, issues),
  retry: Object.assign(retry, retry),
  video: Object.assign(video, video),
  duration: Object.assign(duration, duration),
  voiceOver: Object.assign(voiceOver, voiceOver004910),
}

export default shots
