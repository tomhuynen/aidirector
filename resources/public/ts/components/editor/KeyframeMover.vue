<template>
  <!-- Lies over the keyframe image: click the person, then drag them. The controls are in the column on the right. -->
  <div
    ref="surface"
    class="absolute inset-0 overflow-hidden rounded-xl"
    :class="mover.state.picked ? 'cursor-default' : 'cursor-crosshair'"
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
        :alt="$t('The person to move')"
        class="absolute cursor-grab touch-none drop-shadow-lg active:cursor-grabbing"
        :style="boxStyle(mover.state.picked.box, mover.state.offset.dx, mover.state.offset.dy, mover.scale.value)"
        draggable="false"
        @pointerdown.stop="grab"
        @click.stop
      />
    </template>
    <span
      v-else-if="mover.state.selecting"
      class="absolute inset-0 flex items-center justify-center bg-background/30 text-signal"
    >
      <LoaderCircle class="size-6 animate-spin" />
    </span>
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { LoaderCircle } from 'lucide-vue-next'
import { useTemplateRef } from 'vue'

import type { KeyframeMoverState, MoverBox } from './keyframeMover'

const props = defineProps<{ mover: KeyframeMoverState }>()

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

const click = (event: MouseEvent) => void props.mover.pick(share(event))

let start = { x: 0, y: 0, dx: 0, dy: 0 }

const drag = (event: PointerEvent) => {
  const point = share(event)
  props.mover.moveBy(start.dx + point.x - start.x, start.dy + point.y - start.y)
}

const grab = (event: PointerEvent) => {
  start = { ...share(event), dx: props.mover.state.offset.dx, dy: props.mover.state.offset.dy }
  window.addEventListener('pointermove', drag)
  window.addEventListener('pointerup', () => window.removeEventListener('pointermove', drag), { once: true })
}
</script>
