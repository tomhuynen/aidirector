import { onBeforeUnmount, type Ref, ref, watch } from 'vue'

/** Where the progress is when the usual time has passed; the rest creeps toward the end until it is ready. */
const AT_USUAL_TIME = 0.9
const CEILING = 0.99

/**
 * When each render was first seen, so the progress goes on after switching
 * shots or reloading the page instead of starting over.
 */
const STORAGE_KEY = 'render-started'
const started = new Map<string, number>(readStored())

function readStored(): [string, number][] {
  try {
    return Object.entries(JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '{}') as Record<string, number>)
  } catch {
    return []
  }
}

function store() {
  try {
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(Object.fromEntries(started)))
  } catch {
    // Without storage the progress starts over after a reload.
  }
}

/**
 * A believable share done from the time passed and the usual time: steady
 * until the usual time, then slower and slower so it never quite arrives.
 */
export function estimatedProgress(elapsedSeconds: number, usualSeconds: number): number {
  const x = Math.max(0, elapsedSeconds) / Math.max(1, usualSeconds)

  if (x <= 1) {
    // Eased out: quick at first, a little slower toward the usual time.
    return AT_USUAL_TIME * (1 - Math.pow(1 - x, 1.6))
  }

  return AT_USUAL_TIME + (CEILING - AT_USUAL_TIME) * (1 - Math.exp(-(x - 1) * 1.5))
}

/**
 * The share of a render that is done, from 0 to 1, while `active` is true;
 * it jumps to 1 once the render is ready.
 *
 * @param key Names the render, such as `keyframe:abc`, to remember when it started.
 * @param startedAt When the server says it started, in ms; otherwise the moment it was first seen.
 */
export function useRenderProgress(
  key: Ref<string>,
  active: Ref<boolean>,
  usualSeconds: Ref<number>,
  startedAt?: Ref<number | null>,
) {
  const progress = ref(active.value ? 0 : 1)
  let timer: ReturnType<typeof setInterval> | null = null

  const tick = () => {
    const from = startedAt?.value ?? started.get(key.value) ?? Date.now()
    progress.value = estimatedProgress((Date.now() - from) / 1000, usualSeconds.value)
  }

  const stop = () => {
    if (timer !== null) {
      clearInterval(timer)
      timer = null
    }
  }

  watch(
    [active, key],
    ([isActive, name]) => {
      stop()

      if (!isActive) {
        if (started.delete(name)) store()
        progress.value = 1

        return
      }

      if (!started.has(name)) {
        started.set(name, Date.now())
        store()
      }

      tick()
      timer = setInterval(tick, 500)
    },
    { immediate: true },
  )

  onBeforeUnmount(stop)

  return progress
}
