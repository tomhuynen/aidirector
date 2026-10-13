import { router } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { postJson, PostJsonError } from '@public/ts/shared/postJson'
import { computed, reactive } from 'vue'

export type MoverBox = { x: number; y: number; width: number; height: number }

/** How far each part of the place is from the camera, on a small grid; only ratios between depths are used. */
export type MoverDepth = { width: number; height: number; values: number[] }

/** A drag shorter than this, as a share of the image, is a click, not a move. */
const MIN_MOVE = 0.01

/**
 * Moving a person in a keyframe by hand: click them in the image, drag them,
 * and let go to put them down. The size follows the depth of the place while
 * dragging; the server hides them behind what stands in front of them.
 * The state lives in the panel, so it is reset when another keyframe is shown.
 */
export function createKeyframeMover() {
  const state = reactive({
    selectUrl: '',
    moveUrl: '',
    picked: null as {
      box: MoverBox
      overlay: string
      cutout: string
      x: number
      y: number
      depth: MoverDepth | null
      /** A thing of the place, such as a landed helicopter: it moves in this keyframe only. */
      placeObject: string | null
    } | null,
    offset: { dx: 0, dy: 0 },
    /** Let go at a new spot: confirm, redraw it there or cancel; it can still be dragged further. */
    dropped: false,
    selecting: false,
    applying: false,
    error: null as string | null,
  })

  const depthAt = (depth: MoverDepth, x: number, y: number) => {
    const column = Math.min(depth.width - 1, Math.max(0, Math.floor(x * depth.width)))
    const row = Math.min(depth.height - 1, Math.max(0, Math.floor(y * depth.height)))

    return depth.values[row * depth.width + column]
  }

  /** Larger closer to the camera, smaller further back: the depth at the old feet over the depth at the new feet. */
  const scale = computed(() => {
    const picked = state.picked

    if (!picked?.depth) return 1

    const feetX = picked.box.x + picked.box.width / 2
    const feetY = Math.min(0.999, picked.box.y + picked.box.height)
    const from = depthAt(picked.depth, feetX, feetY)
    const to = depthAt(picked.depth, feetX + state.offset.dx, Math.min(0.999, feetY + state.offset.dy))

    return to > 0 ? Math.min(3, Math.max(0.3, from / to)) : 1
  })

  const reset = () =>
    Object.assign(state, {
      picked: null,
      offset: { dx: 0, dy: 0 },
      dropped: false,
      selecting: false,
      applying: false,
      error: null,
    })

  /** Finds the person at a point given as shares of the image width and height. */
  const pick = async (urls: { selectUrl: string; moveUrl: string }, point: { x: number; y: number }) => {
    if (state.selecting || state.applying) return

    reset()
    Object.assign(state, { ...urls, selecting: true })

    try {
      const found = await postJson<{
        box: MoverBox
        overlay: string
        cutout: string
        depth: MoverDepth | null
        placeObject: string | null
      }>(state.selectUrl, point)
      state.picked = { ...found, ...point }
    } catch (caught) {
      state.error = caught instanceof PostJsonError ? caught.message : $t('Nothing to move was found there. Try again.')
    } finally {
      state.selecting = false
    }
  }

  /** Let go at a new spot: it waits there for confirm, redraw or cancel; a click without a drag only keeps it selected. */
  const drop = () => {
    state.dropped = Boolean(state.picked) && Math.hypot(state.offset.dx, state.offset.dy) >= MIN_MOVE
  }

  /** Puts it down where it was dropped; redrawn, it is drawn again there with its own shadow and light. */
  const apply = (redraw: boolean) => {
    if (!state.picked || !state.dropped || state.applying) return

    state.applying = true
    router.post(
      state.moveUrl,
      { x: state.picked.x, y: state.picked.y, dx: state.offset.dx, dy: state.offset.dy, scale: scale.value, redraw },
      {
        preserveScroll: true,
        onSuccess: reset,
        onError: (errors) => {
          // Dropped, so the drag hint gives way to what went wrong.
          Object.assign(state, {
            picked: null,
            offset: { dx: 0, dy: 0 },
            dropped: false,
            applying: false,
            error: Object.values(errors)[0] ?? null,
          })
        },
      },
    )
  }

  /** Moves the person's feet by shares of the image width and height. */
  const moveBy = (dx: number, dy: number) => (state.offset = { dx, dy })

  return { state, scale, pick, drop, apply, cancel: reset, moveBy }
}

export type KeyframeMoverState = ReturnType<typeof createKeyframeMover>
