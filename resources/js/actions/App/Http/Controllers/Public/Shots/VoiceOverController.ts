import {
  applyUrlDefaults,
  queryParams,
  type RouteDefinition,
  type RouteQueryOptions,
} from './../../../../../../wayfinder'
/**
 * @see \App\Http\Controllers\Public\Shots\VoiceOverController::store
 * @see app/Http/Controllers/Public/Shots/VoiceOverController.php:19
 * @route '/projects/{project}/shots/{shot}/voice-over'
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
  url: '/projects/{project}/shots/{shot}/voice-over',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\VoiceOverController::store
 * @see app/Http/Controllers/Public/Shots/VoiceOverController.php:19
 * @route '/projects/{project}/shots/{shot}/voice-over'
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
 * @see \App\Http\Controllers\Public\Shots\VoiceOverController::store
 * @see app/Http/Controllers/Public/Shots/VoiceOverController.php:19
 * @route '/projects/{project}/shots/{shot}/voice-over'
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
 * @see \App\Http\Controllers\Public\Shots\VoiceOverController::audio
 * @see app/Http/Controllers/Public/Shots/VoiceOverController.php:37
 * @route '/projects/{project}/shots/{shot}/voice-over/audio'
 */
export const audio = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: audio.url(args, options),
  method: 'post',
})

audio.definition = {
  methods: ['post'],
  url: '/projects/{project}/shots/{shot}/voice-over/audio',
} satisfies RouteDefinition<['post']>

/**
 * @see \App\Http\Controllers\Public\Shots\VoiceOverController::audio
 * @see app/Http/Controllers/Public/Shots/VoiceOverController.php:37
 * @route '/projects/{project}/shots/{shot}/voice-over/audio'
 */
audio.url = (
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
    audio.definition.url
      .replace('{project}', parsedArgs.project.toString())
      .replace('{shot}', parsedArgs.shot.toString())
      .replace(/\/+$/, '') + queryParams(options)
  )
}

/**
 * @see \App\Http\Controllers\Public\Shots\VoiceOverController::audio
 * @see app/Http/Controllers/Public/Shots/VoiceOverController.php:37
 * @route '/projects/{project}/shots/{shot}/voice-over/audio'
 */
audio.post = (
  args:
    | { project: string | number | { sqid: string | number }; shot: string | number | { sqid: string | number } }
    | [project: string | number | { sqid: string | number }, shot: string | number | { sqid: string | number }],
  options?: RouteQueryOptions,
): RouteDefinition<'post'> => ({
  url: audio.url(args, options),
  method: 'post',
})

const VoiceOverController = { store, audio }

export default VoiceOverController
