import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
  validateParameters,
} from './../../../../wayfinder'
import first from './first'
/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\StoreController::store
 * @see app/Http/Controllers/Public/Shots/Keyframes/StoreController.php:25
 * @route '/projects/{project}/shots/{shot}/keyframes'
 */
export const store = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

store.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\StoreController::store
 * @see app/Http/Controllers/Public/Shots/Keyframes/StoreController.php:25
 * @route '/projects/{project}/shots/{shot}/keyframes'
 */
store.url = (
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
    store.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\StoreController::store
 * @see app/Http/Controllers/Public/Shots/Keyframes/StoreController.php:25
 * @route '/projects/{project}/shots/{shot}/keyframes'
 */
store.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: store.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\ReorderController::reorder
 * @see app/Http/Controllers/Public/Shots/Keyframes/ReorderController.php:20
 * @route '/projects/{project}/shots/{shot}/keyframes/reorder'
 */
export const reorder = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: reorder.url(args, options),
  method: 'post',
})

reorder.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/reorder',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\ReorderController::reorder
 * @see app/Http/Controllers/Public/Shots/Keyframes/ReorderController.php:20
 * @route '/projects/{project}/shots/{shot}/keyframes/reorder'
 */
reorder.url = (
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
    reorder.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\ReorderController::reorder
 * @see app/Http/Controllers/Public/Shots/Keyframes/ReorderController.php:20
 * @route '/projects/{project}/shots/{shot}/keyframes/reorder'
 */
reorder.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: reorder.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/Keyframes/DestroyController.php:21
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}'
 */
export const destroy = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

destroy.definition = {
  methods: ['delete'],
  url: '/projects/{project}/shots/{shot}/keyframes/{keyframe}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/Keyframes/DestroyController.php:21
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}'
 */
destroy.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      keyframe: args[2],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    keyframe: typeof args.keyframe === 'object' ? args.keyframe.sqid : args.keyframe,
  }

  return (
    destroy.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{keyframe}', parsedArgs.keyframe.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\DestroyController::destroy
 * @see app/Http/Controllers/Public/Shots/Keyframes/DestroyController.php:21
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}'
 */
destroy.delete = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\CopyController::copy
 * @see app/Http/Controllers/Public/Shots/Keyframes/CopyController.php:26
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/copy'
 */
export const copy = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: copy.url(args, options),
  method: 'post',
})

copy.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/{keyframe}/copy',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\CopyController::copy
 * @see app/Http/Controllers/Public/Shots/Keyframes/CopyController.php:26
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/copy'
 */
copy.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      keyframe: args[2],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    keyframe: typeof args.keyframe === 'object' ? args.keyframe.sqid : args.keyframe,
  }

  return (
    copy.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{keyframe}', parsedArgs.keyframe.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\CopyController::copy
 * @see app/Http/Controllers/Public/Shots/Keyframes/CopyController.php:26
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/copy'
 */
copy.post = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: copy.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\GenerateController::generate
 * @see app/Http/Controllers/Public/Shots/Keyframes/GenerateController.php:21
 * @route '/projects/{project}/shots/{shot}/keyframes/generate'
 */
export const generate = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: generate.url(args, options),
  method: 'post',
})

generate.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/generate',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\GenerateController::generate
 * @see app/Http/Controllers/Public/Shots/Keyframes/GenerateController.php:21
 * @route '/projects/{project}/shots/{shot}/keyframes/generate'
 */
generate.url = (
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
    generate.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\GenerateController::generate
 * @see app/Http/Controllers/Public/Shots/Keyframes/GenerateController.php:21
 * @route '/projects/{project}/shots/{shot}/keyframes/generate'
 */
generate.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: generate.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\UpdateController::update
 * @see app/Http/Controllers/Public/Shots/Keyframes/UpdateController.php:34
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/update'
 */
export const update = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: update.url(args, options),
  method: 'post',
})

update.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/{keyframe}/update',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\UpdateController::update
 * @see app/Http/Controllers/Public/Shots/Keyframes/UpdateController.php:34
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/update'
 */
update.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      keyframe: args[2],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    keyframe: typeof args.keyframe === 'object' ? args.keyframe.sqid : args.keyframe,
  }

  return (
    update.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{keyframe}', parsedArgs.keyframe.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\UpdateController::update
 * @see app/Http/Controllers/Public/Shots/Keyframes/UpdateController.php:34
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/update'
 */
update.post = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: update.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\TweakController::tweak
 * @see app/Http/Controllers/Public/Shots/Keyframes/TweakController.php:25
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/tweak'
 */
export const tweak = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: tweak.url(args, options),
  method: 'post',
})

tweak.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/{keyframe}/tweak',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\TweakController::tweak
 * @see app/Http/Controllers/Public/Shots/Keyframes/TweakController.php:25
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/tweak'
 */
tweak.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      keyframe: args[2],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    keyframe: typeof args.keyframe === 'object' ? args.keyframe.sqid : args.keyframe,
  }

  return (
    tweak.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{keyframe}', parsedArgs.keyframe.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\TweakController::tweak
 * @see app/Http/Controllers/Public/Shots/Keyframes/TweakController.php:25
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/tweak'
 */
tweak.post = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: tweak.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\LayoutController::layout
 * @see app/Http/Controllers/Public/Shots/Keyframes/LayoutController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/layout'
 */
export const layout = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: layout.url(args, options),
  method: 'post',
})

layout.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/{keyframe}/layout',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\LayoutController::layout
 * @see app/Http/Controllers/Public/Shots/Keyframes/LayoutController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/layout'
 */
layout.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      keyframe: args[2],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    keyframe: typeof args.keyframe === 'object' ? args.keyframe.sqid : args.keyframe,
  }

  return (
    layout.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{keyframe}', parsedArgs.keyframe.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\LayoutController::layout
 * @see app/Http/Controllers/Public/Shots/Keyframes/LayoutController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/layout'
 */
layout.post = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: layout.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\MoveController::move
 * @see app/Http/Controllers/Public/Shots/Keyframes/MoveController.php:28
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/move'
 */
export const move = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: move.url(args, options),
  method: 'post',
})

move.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/{keyframe}/move',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\MoveController::move
 * @see app/Http/Controllers/Public/Shots/Keyframes/MoveController.php:28
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/move'
 */
move.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      keyframe: args[2],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    keyframe: typeof args.keyframe === 'object' ? args.keyframe.sqid : args.keyframe,
  }

  return (
    move.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{keyframe}', parsedArgs.keyframe.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\MoveController::move
 * @see app/Http/Controllers/Public/Shots/Keyframes/MoveController.php:28
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/move'
 */
move.post = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: move.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\RenderController::render
 * @see app/Http/Controllers/Public/Shots/Keyframes/RenderController.php:25
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/render'
 */
export const render = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: render.url(args, options),
  method: 'post',
})

render.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/keyframes/{keyframe}/render',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\RenderController::render
 * @see app/Http/Controllers/Public/Shots/Keyframes/RenderController.php:25
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/render'
 */
render.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      keyframe: args[2],
    }
  }

  args = applyUrlDefaults(args)

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    keyframe: typeof args.keyframe === 'object' ? args.keyframe.sqid : args.keyframe,
  }

  return (
    render.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{keyframe}', parsedArgs.keyframe.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\RenderController::render
 * @see app/Http/Controllers/Public/Shots/Keyframes/RenderController.php:25
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/render'
 */
render.post = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: render.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\ImageController::image
 * @see app/Http/Controllers/Public/Shots/Keyframes/ImageController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/image/{conversion?}'
 */
export const image = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
        conversion?: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
        conversion: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: image.url(args, options),
  method: 'get',
})

image.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/shots/{shot}/keyframes/{keyframe}/image/{conversion?}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\ImageController::image
 * @see app/Http/Controllers/Public/Shots/Keyframes/ImageController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/image/{conversion?}'
 */
image.url = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
        conversion?: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
        conversion: string | number,
      ],
  options?: RouteQueryOptions,
) => {
  if (Array.isArray(args)) {
    args = {
      project: args[0],
      shot: args[1],
      keyframe: args[2],
      conversion: args[3],
    }
  }

  args = applyUrlDefaults(args)

  validateParameters(args, ['conversion'])

  const parsedArgs = {
    project: typeof args.project === 'object' ? args.project.sqid : args.project,
    shot: typeof args.shot === 'object' ? args.shot.sqid : args.shot,
    keyframe: typeof args.keyframe === 'object' ? args.keyframe.sqid : args.keyframe,
    conversion: args.conversion,
  }

  return (
    image.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace('{keyframe}', parsedArgs.keyframe.toString())
      .replace('{conversion?}', parsedArgs.conversion?.toString() ?? '')
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\ImageController::image
 * @see app/Http/Controllers/Public/Shots/Keyframes/ImageController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/image/{conversion?}'
 */
image.get = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
        conversion?: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
        conversion: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: image.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Keyframes\ImageController::image
 * @see app/Http/Controllers/Public/Shots/Keyframes/ImageController.php:24
 * @route '/projects/{project}/shots/{shot}/keyframes/{keyframe}/image/{conversion?}'
 */
image.head = (
  args:
    | {
        project: string | number | { sqid: string | number }
        shot: string | number | { sqid: string | number }
        keyframe: string | number | { sqid: string | number }
        conversion?: string | number
      }
    | [
        project: string | number | { sqid: string | number },
        shot: string | number | { sqid: string | number },
        keyframe: string | number | { sqid: string | number },
        conversion: string | number,
      ],
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: image.url(args, options),
  method: 'head',
})

const keyframes = {
  store: Object.assign(store, store),
  reorder: Object.assign(reorder, reorder),
  destroy: Object.assign(destroy, destroy),
  copy: Object.assign(copy, copy),
  generate: Object.assign(generate, generate),
  first: Object.assign(first, first),
  update: Object.assign(update, update),
  tweak: Object.assign(tweak, tweak),
  layout: Object.assign(layout, layout),
  move: Object.assign(move, move),
  render: Object.assign(render, render),
  image: Object.assign(image, image),
}

export default keyframes
