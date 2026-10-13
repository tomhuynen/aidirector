<template>
  <!-- Lies over the keyframe image: click a person or something that moves to pick it, drag it, let go to put it down. -->
  <div
    ref="surface"
    class="absolute inset-0 overflow-hidden rounded-xl"
    :class="mover.state.picked ? 'cursor-default' : 'cursor-pointer'"
    :title="
      mover.state.picked
        ? undefined
        : $t('Click a person or something that moves to move it, or to say in the chat what should change')
    "
    @click="click"
  >
    <template v-if="mover.state.picked">
      <!-- Where the person stood: the place shows there again. -->
      <img
        :src="mover.state.picked.overlay"
        alt=""
        class="pointer-events-none absolute"
        :style="boxStyle(mover.state.picked.box, 0, 0, 1)"
        draggable="false"
      />
      <img
        :src="mover.state.picked.cutout"
        :alt="$t('What to move')"
        class="absolute cursor-grab touch-none drop-shadow-lg active:cursor-grabbing"
        :style="boxStyle(mover.state.picked.box, mover.state.offset.dx, mover.state.offset.dy, mover.scale.value)"
        draggable="false"
        @pointerdown.stop="grab"
        @click.stop
      />
    </template>
    <span
      v-if="mover.state.selecting || mover.state.applying"
      class="absolute inset-0 flex items-center justify-center bg-background/30 text-signal"
    >
      <LoaderCircle class="size-6 animate-spin" />
    </span>
    <!-- Let go at a new spot: keep it there as it is, draw it again there with its own shadow and light, or put it back. -->
    <div
      v-if="mover.state.picked && mover.state.dropped && !mover.state.applying"
      class="absolute inset-x-4 bottom-4 flex flex-wrap justify-center gap-2"
      @click.stop
    >
      <Button type="button" class="shadow-lg" @click="mover.apply(false)">
        <Check class="size-4" />
        {{ $t('Confirm') }}
      </Button>
      <Button
        type="button"
        variant="outline"
        class="bg-background/90 shadow-lg"
        :title="$t('Draw it again at this spot, with its own shadow and light')"
        @click="mover.apply(true)"
      >
        <Sparkles class="size-4" />
        {{ $t('Redraw') }}
      </Button>
      <Button type="button" variant="outline" class="bg-background/90 shadow-lg" @click="mover.cancel()">
        <X class="size-4" />
        {{ $t('Cancel') }}
      </Button>
    </div>
    <p
      v-else-if="mover.state.picked && !mover.state.applying"
      class="pointer-events-none absolute inset-x-4 bottom-4 rounded-lg border border-border bg-background/90 px-4 py-2 text-center text-sm"
    >
      {{
        mover.state.picked.placeObject
          ? $t(':name is part of the place: it moves in this keyframe only. Drag it and let go. Esc cancels.', {
              name: mover.state.picked.placeObject,
            })
          : $t('Drag it to the new spot and let go, or say in the chat what should change. Esc cancels.')
      }}
    </p>
    <p
      v-else-if="mover.state.error"
      class="absolute inset-x-4 bottom-4 rounded-lg border border-destructive/50 bg-background/90 px-4 py-2 text-center text-sm text-destructive"
    >
      {{ mover.state.error }}
    </p>
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { Button } from '@shared:ui/button'
import { Check, LoaderCircle, Sparkles, X } from 'lucide-vue-next'
import { onBeforeUnmount, onMounted, useTemplateRef } from 'vue'

import type { KeyframeMoverState, MoverBox } from './keyframeMover'

const props = defineProps<{ mover: KeyframeMoverState; selectUrl: string; moveUrl: string }>()

const surface = useTemplateRef<HTMLElement>('surface')

/** The box moved and scaled around its feet, as percentages of the image. */
const boxStyle = (box: MoverBox, dx: number, dy: number, size: number) => {
  const width = box.width * size
  const height = box.height * size
  const feetX = box.x + box.width / 2 + dx
  const feetY = box.y + box.height + dy

  return {
    left: `${(feetX - width / 2) * 100}%`,
    top: `${(feetY - height) * 100}%`,
    width: `${width * 100}%`,
    height: `${height * 100}%`,
  }
}

/** A point on the image as shares of its width and height. */
const share = (event: PointerEvent | MouseEvent) => {
  const rect = surface.value?.getBoundingClientRect()

  if (!rect) return { x: 0, y: 0 }

  return {
    x: Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)),
    y: Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height)),
  }
}

/** A click picks the person there, or another one when someone is picked already. */
const click = (event: MouseEvent) =>
  void props.mover.pick({ selectUrl: props.selectUrl, moveUrl: props.moveUrl }, share(event))

let start = { x: 0, y: 0, dx: 0, dy: 0 }

const drag = (event: PointerEvent) => {
  const point = share(event)
  props.mover.moveBy(start.dx + point.x - start.x, start.dy + point.y - start.y)
}

const grab = (event: PointerEvent) => {
  start = { ...share(event), dx: props.mover.state.offset.dx, dy: props.mover.state.offset.dy }
  window.addEventListener('pointermove', drag)
  window.addEventListener(
    'pointerup',
    () => {
      window.removeEventListener('pointermove', drag)
      props.mover.drop()
    },
    { once: true },
  )
}

const escape = (event: KeyboardEvent) => {
  if (event.key === 'Escape' && props.mover.state.picked && !props.mover.state.applying) props.mover.cancel()
}

onMounted(() => window.addEventListener('keydown', escape))
onBeforeUnmount(() => window.removeEventListener('keydown', escape))
</script>
