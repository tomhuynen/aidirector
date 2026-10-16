import { applyUrlDefaults, queryParams, type RouteDefinition, type RouteQueryOptions } from './../../../wayfinder'
import branding from './branding'
import cover from './cover'
import elements from './elements'
import rules from './rules'
import style from './style'
/**
 * @see \App\Http\Controllers\Public\Projects\IndexController::index
 * @see app/Http/Controllers/Public/Projects/IndexController.php:16
 * @route '/projects'
 */
export const index = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

index.definition = {
  methods: ['get', 'head'],
  url: '/projects',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\IndexController::index
 * @see app/Http/Controllers/Public/Projects/IndexController.php:16
 * @route '/projects'
 */
index.url = (options?: RouteQueryOptions) => {
  return index.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Projects\IndexController::index
 * @see app/Http/Controllers/Public/Projects/IndexController.php:16
 * @route '/projects'
 */
index.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: index.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\IndexController::index
 * @see app/Http/Controllers/Public/Projects/IndexController.php:16
 * @route '/projects'
 */
index.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: index.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::create
 * @see app/Http/Controllers/Public/Projects/CreateController.php:22
 * @route '/projects/create'
 */
export const create = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: create.url(options),
  method: 'get',
})

create.definition = {
  methods: ['get', 'head'],
  url: '/projects/create',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::create
 * @see app/Http/Controllers/Public/Projects/CreateController.php:22
 * @route '/projects/create'
 */
create.url = (options?: RouteQueryOptions) => {
  return create.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::create
 * @see app/Http/Controllers/Public/Projects/CreateController.php:22
 * @route '/projects/create'
 */
create.get = (options?: RouteQueryOptions): RouteDefinition<'get'> => ({
  url: create.url(options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::create
 * @see app/Http/Controllers/Public/Projects/CreateController.php:22
 * @route '/projects/create'
 */
create.head = (options?: RouteQueryOptions): RouteDefinition<'head'> => ({
  url: create.url(options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Projects\ChatController::chat
 * @see app/Http/Controllers/Public/Projects/ChatController.php:57
 * @route '/projects/create/chat'
 */
export const chat = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: chat.url(options),
  method: 'post',
})

chat.definition = {
  methods: ['post'],
  url: '/projects/create/chat',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\ChatController::chat
 * @see app/Http/Controllers/Public/Projects/ChatController.php:57
 * @route '/projects/create/chat'
 */
chat.url = (options?: RouteQueryOptions) => {
  return chat.definition.url + queryParams(options)
}

/**
 * @see \App\Http\Controllers\Public\Projects\ChatController::chat
 * @see app/Http/Controllers/Public/Projects/ChatController.php:57
 * @route '/projects/create/chat'
 */
chat.post = (options?: RouteQueryOptions): RouteDefinition<'post'> => ({
  url: chat.url(options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Projects\ViewController::view
 * @see app/Http/Controllers/Public/Projects/ViewController.php:29
 * @route '/projects/{project}'
 */
export const view = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

view.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\ViewController::view
 * @see app/Http/Controllers/Public/Projects/ViewController.php:29
 * @route '/projects/{project}'
 */
view.url = (
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
    view.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\ViewController::view
 * @see app/Http/Controllers/Public/Projects/ViewController.php:29
 * @route '/projects/{project}'
 */
view.get = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: view.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\ViewController::view
 * @see app/Http/Controllers/Public/Projects/ViewController.php:29
 * @route '/projects/{project}'
 */
view.head = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: view.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::setup
 * @see app/Http/Controllers/Public/Projects/CreateController.php:38
 * @route '/projects/{project}/setup'
 */
export const setup = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: setup.url(args, options),
  method: 'get',
})

setup.definition = {
  methods: ['get', 'head'],
  url: '/projects/{project}/setup',
} satisfies RouteDefinition<['get', 'head']>

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::setup
 * @see app/Http/Controllers/Public/Projects/CreateController.php:38
 * @route '/projects/{project}/setup'
 */
setup.url = (
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
    setup.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::setup
 * @see app/Http/Controllers/Public/Projects/CreateController.php:38
 * @route '/projects/{project}/setup'
 */
setup.get = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'get'> => ({
  url: setup.url(args, options),
  method: 'get',
})

/**
 * @see \App\Http\Controllers\Public\Projects\CreateController::setup
 * @see app/Http/Controllers/Public/Projects/CreateController.php:38
 * @route '/projects/{project}/setup'
 */
setup.head = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'head'> => ({
  url: setup.url(args, options),
  method: 'head',
})

/**
 * @see \App\Http\Controllers\Public\Projects\FormatController::format
 * @see app/Http/Controllers/Public/Projects/FormatController.php:17
 * @route '/projects/{project}/format'
 */
export const format = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: format.url(args, options),
  method: 'post',
})

format.definition = {
  methods: ['post'],
  url: '/projects/{project}/format',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\FormatController::format
 * @see app/Http/Controllers/Public/Projects/FormatController.php:17
 * @route '/projects/{project}/format'
 */
format.url = (
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
    format.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\FormatController::format
 * @see app/Http/Controllers/Public/Projects/FormatController.php:17
 * @route '/projects/{project}/format'
 */
format.post = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: format.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Projects\VoiceOverController::voiceOver
 * @see app/Http/Controllers/Public/Projects/VoiceOverController.php:17
 * @route '/projects/{project}/voice-over'
 */
export const voiceOver = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: voiceOver.url(args, options),
  method: 'post',
})

voiceOver.definition = {
  methods: ['post'],
  url: '/projects/{project}/voice-over',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Projects\VoiceOverController::voiceOver
 * @see app/Http/Controllers/Public/Projects/VoiceOverController.php:17
 * @route '/projects/{project}/voice-over'
 */
voiceOver.url = (
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
    voiceOver.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') +
    queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\VoiceOverController::voiceOver
 * @see app/Http/Controllers/Public/Projects/VoiceOverController.php:17
 * @route '/projects/{project}/voice-over'
 */
voiceOver.post = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: voiceOver.url(args, options),
  method: 'post',
})

/**
 * @see \App\Http\Controllers\Public\Projects\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/DestroyController.php:14
 * @route '/projects/{project}'
 */
export const destroy = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

destroy.definition = {
  methods: ['delete'],
  url: '/projects/{project}',
} satisfies RouteDefinition<['delete']>

/**
 * @see \App\Http\Controllers\Public\Projects\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/DestroyController.php:14
 * @route '/projects/{project}'
 */
destroy.url = (
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
    destroy.definition.url.replace('{project}', parsedArgs.project.toString()).replace(/\/+$/, '') +
    queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Projects\DestroyController::destroy
 * @see app/Http/Controllers/Public/Projects/DestroyController.php:14
 * @route '/projects/{project}'
 */
destroy.delete = (
  args:
    | { project: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }]
    | string
    | number
    | { sqid: string | number },
  options?: RouteQueryOptions,
): RouteDefinition<'delete'> => ({
  url: destroy.url(args, options),
  method: 'delete',
})

const projects = {
  index: Object.assign(index, index),
  create: Object.assign(create, create),
  chat: Object.assign(chat, chat),
  view: Object.assign(view, view),
  setup: Object.assign(setup, setup),
  cover: Object.assign(cover, cover),
  format: Object.assign(format, format),
  voiceOver: Object.assign(voiceOver, voiceOver),
  rules: Object.assign(rules, rules),
  destroy: Object.assign(destroy, destroy),
  branding: Object.assign(branding, branding),
  elements: Object.assign(elements, elements),
  style: Object.assign(style, style),
}

export default projects
