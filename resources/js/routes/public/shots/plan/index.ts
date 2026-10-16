import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../../wayfinder'
import elementsE1f321 from './elements'
/**
 * @see \App\Http\Controllers\Public\Shots\Plan\DirectorController::chat
 * @see app/Http/Controllers/Public/Shots/Plan/DirectorController.php:45
 * @route '/projects/{project}/shots/{shot}/plan/chat'
 */
export const chat = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: chat.url(args, options),
  method: 'post',
})

chat.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/plan/chat',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Plan\DirectorController::chat
 * @see app/Http/Controllers/Public/Shots/Plan/DirectorController.php:45
 * @route '/projects/{project}/shots/{shot}/plan/chat'
 */
chat.url = (
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
    chat.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Plan\DirectorController::chat
 * @see app/Http/Controllers/Public/Shots/Plan/DirectorController.php:45
 * @route '/projects/{project}/shots/{shot}/plan/chat'
 */
chat.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: chat.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Plan\ChangeController::change
 * @see app/Http/Controllers/Public/Shots/Plan/ChangeController.php:30
 * @route '/projects/{project}/shots/{shot}/plan/change'
 */
export const change = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: change.url(args, options),
  method: 'post',
})

change.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/plan/change',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Plan\ChangeController::change
 * @see app/Http/Controllers/Public/Shots/Plan/ChangeController.php:30
 * @route '/projects/{project}/shots/{shot}/plan/change'
 */
change.url = (
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
    change.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Plan\ChangeController::change
 * @see app/Http/Controllers/Public/Shots/Plan/ChangeController.php:30
 * @route '/projects/{project}/shots/{shot}/plan/change'
 */
change.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: change.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Shots\Plan\VoiceOverController::voiceOver
 * @see app/Http/Controllers/Public/Shots/Plan/VoiceOverController.php:22
 * @route '/projects/{project}/shots/{shot}/plan/voice-over'
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
  url: '/projects/{project}/shots/{shot}/plan/voice-over',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Plan\VoiceOverController::voiceOver
 * @see app/Http/Controllers/Public/Shots/Plan/VoiceOverController.php:22
 * @route '/projects/{project}/shots/{shot}/plan/voice-over'
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
 * @see \App\Http\Controllers\Public\Shots\Plan\VoiceOverController::voiceOver
 * @see app/Http/Controllers/Public/Shots/Plan/VoiceOverController.php:22
 * @route '/projects/{project}/shots/{shot}/plan/voice-over'
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
 * @see \App\Http\Controllers\Public\Shots\Plan\ElementsController::elements
 * @see app/Http/Controllers/Public/Shots/Plan/ElementsController.php:23
 * @route '/projects/{project}/shots/{shot}/plan/elements'
 */
export const elements = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: elements.url(args, options),
  method: 'post',
})

elements.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/plan/elements',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\Plan\ElementsController::elements
 * @see app/Http/Controllers/Public/Shots/Plan/ElementsController.php:23
 * @route '/projects/{project}/shots/{shot}/plan/elements'
 */
elements.url = (
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
    elements.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\Plan\ElementsController::elements
 * @see app/Http/Controllers/Public/Shots/Plan/ElementsController.php:23
 * @route '/projects/{project}/shots/{shot}/plan/elements'
 */
elements.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: elements.url(args, options),
  method: 'post',
})

const plan = {
  chat: Object.assign(chat, chat),
  change: Object.assign(change, change),
  voiceOver: Object.assign(voiceOver, voiceOver),
  elements: Object.assign(elements, elementsE1f321),
}

export default plan
