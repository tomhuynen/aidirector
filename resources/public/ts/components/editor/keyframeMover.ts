import { router } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { postJson, PostJsonError } from '@public/ts/shared/postJson'
import { computed, reactive } from 'vue'

export type MoverBox = { x: number; y: number; width: number; height: number }

/** Where the horizon roughly lies in these keyframes, as a share of the height; people shrink towards it. */
const HORIZON = 0.42

/**
 * Moving a person in a keyframe by hand. The image shows the person and
 * takes the click and the drag; the column on the right shows the controls.
 * Both share this state, which lives in the panel, so it is reset after the
 * move is sent or cancelled even when the image is no longer shown.
 */
export function createKeyframeMover() {
  const state = reactive({
    active: false,
    selectUrl: '',
    moveUrl: '',
    picked: null as { box: MoverBox; overlay: string; cutout: string; x: number; y: number } | null,
    offset: { dx: 0, dy: 0 },
    factor: 1,
    instruction: '',
    selecting: false,
    applying: false,
    error: null as string | null,
  })

  /** Larger closer to the camera, smaller further back, times the director's own correction. */
  const scale = computed(() => {
    if (!state.picked) return 1

    const feet = state.picked.box.y + state.picked.box.height
    const depth = feet > HORIZON + 0.02 ? (feet + state.offset.dy - HORIZON) / (feet - HORIZON) : 1

    return Math.min(3, Math.max(0.3, Math.min(2.5, Math.max(0.4, depth)) * state.factor))
  })

  const reset = () =>
    Object.assign(state, {
      active: false,
      picked: null,
      offset: { dx: 0, dy: 0 },
      factor: 1,
      instruction: '',
      selecting: false,
      applying: false,
      error: null,
    })

  const start = (selectUrl: string, moveUrl: string) => {
    reset()
    Object.assign(state, { active: true, selectUrl, moveUrl })
  }

  /** Finds the person at a point given as shares of the image width and height. */
  const pick = async (point: { x: number; y: number }) => {
    if (!state.active || state.picked || state.selecting) return

    state.selecting = true
    state.error = null

    try {
      const found = await postJson<{ box: MoverBox; overlay: string; cutout: string }>(state.selectUrl, point)
      state.picked = { ...found, ...point }
    } catch (caught) {
      state.error = caught instanceof PostJsonError ? caught.message : $t('The person could not be found. Try again.')
    } finally {
      state.selecting = false
    }
  }

  const apply = () => {
    if (!state.picked) return

    state.applying = true
    router.post(
      state.moveUrl,
      {
        x: state.picked.x,
        y: state.picked.y,
        dx: state.offset.dx,
        dy: state.offset.dy,
        scale: scale.value,
        instruction: state.instruction,
      },
      {
        preserveScroll: true,
        onSuccess: reset,
        onError: (errors) => {
          state.error = Object.values(errors)[0] ?? null
          state.applying = false
        },
      },
    )
  }

  const setFactor = (factor: number) => (state.factor = factor)
  const setInstruction = (instruction: string) => (state.instruction = instruction)
  /** Moves the person's feet by shares of the image width and height. */
  const moveBy = (dx: number, dy: number) => (state.offset = { dx, dy })

  return { state, scale, start, cancel: reset, pick, apply, setFactor, setInstruction, moveBy }
}

export type KeyframeMoverState = ReturnType<typeof createKeyframeMover>
