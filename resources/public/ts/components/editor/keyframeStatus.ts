/** Where a keyframe is in being drawn; it waits for the keyframe it is drawn from, so keyframes are drawn in order. */
export type KeyframeStatus = 'planned' | 'waiting' | 'drawing' | 'checking' | 'ready' | 'failed'

/** Whether work for the keyframe is queued or running. */
export const isBusy = (status: KeyframeStatus | null | undefined): boolean =>
  status === 'waiting' || status === 'drawing' || status === 'checking'
