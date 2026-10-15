<template>
  <div class="pointer-events-none absolute inset-0">
    <!-- The tools sit in a bar above the keyframe, with Cancel and Confirm once something was dragged. -->
    <Teleport defer to="#keyframe-tools">
      <div class="flex items-center gap-3">
        <div class="flex items-center gap-1 rounded-xl border border-border bg-card p-1" role="group">
          <button
            v-for="tool in tools"
            :key="tool.value"
            type="button"
            :disabled="disabled && tool.value === 'move'"
            :aria-pressed="mode === tool.value"
            :class="
              cn(
                'flex h-8 items-center gap-2 rounded-lg px-3 text-sm font-medium transition-colors disabled:pointer-events-none disabled:opacity-50',
                mode === tool.value
                  ? 'bg-signal text-primary-foreground'
                  : 'text-foreground/80 hover:bg-accent hover:text-foreground',
              )
            "
            @click="choose(tool.value)"
          >
            <component :is="tool.icon" class="size-4" />
            {{ tool.label }}
          </button>
        </div>
        <template v-if="moved">
          <Button type="button" variant="outline" size="sm" @click="reset">
            <X class="size-4" />
            {{ $t('Cancel') }}
          </Button>
          <Button type="button" variant="outline" size="sm" :disabled="move.processing" @click="confirm">
            <Check class="size-4" />
            {{ $t('Confirm') }}
          </Button>
        </template>
      </div>
    </Teleport>

    <!-- Reading where everyone stands, for a keyframe without a layout yet. -->
    <p
      v-if="loading"
      class="absolute bottom-4 left-1/2 flex -translate-x-1/2 items-center gap-2 rounded-lg border border-border bg-background/90 px-4 py-3 text-sm whitespace-nowrap text-signal"
    >
      <LoaderCircle class="size-4 shrink-0 animate-spin" />
      {{ $t('Finding the people and objects…') }}
    </p>

    <div
      v-else-if="mode === 'select' || !disabled"
      class="pointer-events-auto absolute inset-0 touch-none"
      :class="
        dragging ? 'cursor-grabbing' : hovered ? (mode === 'move' ? 'cursor-grab' : 'cursor-pointer') : 'cursor-default'
      "
      @pointerdown="press"
      @pointermove="drag"
      @pointerup="drop"
      @pointercancel="drop"
      @pointerleave="hovered = null"
    >
      <template v-if="picked">
        <div
          class="absolute rounded-md border-2 border-dashed border-white/50"
          :style="place(picked.from)"
          aria-hidden="true"
        />
        <div
          class="absolute rounded-md border-2 border-signal bg-signal/15 shadow-[0_0_0_1px_rgba(0,0,0,0.4)]"
          :style="place(picked.to)"
          aria-hidden="true"
        />
      </template>
      <div
        v-else-if="selectedMember"
        class="absolute rounded-md border-2 border-signal shadow-[0_0_0_1px_rgba(0,0,0,0.4)]"
        :style="place(selectedMember.box)"
        aria-hidden="true"
      />
      <div
        v-if="hovered && hovered.name !== selectedMember?.name && !picked"
        class="absolute rounded-md border-2 border-white/80"
        :style="place(hovered.box)"
        aria-hidden="true"
      />
    </div>

    <FollowingDialog v-model:open="asking" :count="following" :processing="move.processing" @answer="send" />
  </div>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Check, LoaderCircle, MousePointer2, Move, X } from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import FollowingDialog from './FollowingDialog.vue'

/** A box as top, left, bottom and right on a 0 to 1000 grid of the frame. */
type Box = [number, number, number, number]

export type LayoutMember = { name: string; box: Box }

/**
 * Select and Move for the people and objects in a drawn keyframe. Select
 * is on unless Move is, and works while the keyframe is drawn: it outlines
 * one of them, so the chat is about them. Move drags one to another spot,
 * and on confirm the keyframe is drawn again with only them moved. A
 * keyframe without a layout has it read on the first click or on Move.
 */
const props = defineProps<{
  layout: LayoutMember[]
  layoutUrl: string | null
  moveUrl: string
  /** How many drawn keyframes come after this one; they may be drawn again to follow the move. */
  following: number
  /** The keyframe is being drawn: the tools wait. */
  disabled?: boolean
}>()

/** The name of the one selected, whom the chat is about. */
const selected = defineModel<string | null>('selected', { default: null })

const tools = [
  { value: 'select', label: $t('Select'), icon: MousePointer2 },
  { value: 'move', label: $t('Move'), icon: Move },
] as const

const mode = ref<'select' | 'move'>('select')
const loading = ref(false)
const hovered = ref<LayoutMember | null>(null)
const picked = ref<{ name: string; from: Box; to: Box } | null>(null)
const dragging = ref<{ x: number; y: number } | null>(null)
/** Asking whether the keyframes after this one should follow the move. */
const asking = ref(false)

const reading = useForm({})
const move = useForm<{ name: string; box: Box; following: boolean }>({ name: '', box: [0, 0, 0, 0], following: false })

const selectedMember = computed(() => props.layout.find((member) => member.name === selected.value) ?? null)
const moved = computed(
  () => !dragging.value && picked.value !== null && picked.value.to.some((value, i) => value !== picked.value!.from[i]),
)

const place = (box: Box) => ({
  top: `${box[0] / 10}%`,
  left: `${box[1] / 10}%`,
  height: `${(box[2] - box[0]) / 10}%`,
  width: `${(box[3] - box[1]) / 10}%`,
})

/** Where the pointer is on the 0 to 1000 grid of the image. */
const point = (event: PointerEvent) => {
  const frame = (event.currentTarget as HTMLElement).getBoundingClientRect()

  return {
    x: ((event.clientX - frame.left) / frame.width) * 1000,
    y: ((event.clientY - frame.top) / frame.height) * 1000,
  }
}

/** The smallest box under the pointer, so someone in front of a large vehicle can still be picked. */
const memberAt = (x: number, y: number) =>
  props.layout
    .filter((member) => y >= member.box[0] && y <= member.box[2] && x >= member.box[1] && x <= member.box[3])
    .sort((a, b) => (a.box[2] - a.box[0]) * (a.box[3] - a.box[1]) - (b.box[2] - b.box[0]) * (b.box[3] - b.box[1]))[0] ??
  null

const reset = () => {
  picked.value = null
  dragging.value = null
}

/** Reads where everyone stands, for a keyframe without a layout yet; true while that is needed. */
const readLayout = () => {
  if (props.layout.length > 0 || !props.layoutUrl) return false

  if (!loading.value) {
    loading.value = true
    reading.post(props.layoutUrl, {
      preserveScroll: true,
      preserveState: true,
      onError: () => (mode.value = 'select'),
      onFinish: () => (loading.value = false),
    })
  }

  return true
}

/** Move turns on, or back to Select when it is on already. */
const choose = (tool: 'select' | 'move') => {
  reset()
  hovered.value = null
  mode.value = tool === 'move' && mode.value !== 'move' ? 'move' : 'select'

  if (mode.value === 'move') {
    selected.value = null
    readLayout()
  }
}

const press = (event: PointerEvent) => {
  const { x, y } = point(event)

  if (readLayout()) return

  if (mode.value === 'select') {
    selected.value = memberAt(x, y)?.name ?? null

    return
  }

  if (!picked.value) {
    const member = memberAt(x, y)

    if (!member) return

    picked.value = { name: member.name, from: member.box, to: member.box }
  }

  dragging.value = { x, y }
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
}

const drag = (event: PointerEvent) => {
  const { x, y } = point(event)

  if (!dragging.value || !picked.value) {
    hovered.value = picked.value ? null : memberAt(x, y)

    return
  }

  const [top, left, bottom, right] = picked.value.to
  // Past the edge of the frame is allowed: partly past it the edge cuts them off, wholly past it they leave the shot.
  const dy = Math.max(-1000 - top, Math.min(2000 - bottom, y - dragging.value.y))
  const dx = Math.max(-1000 - left, Math.min(2000 - right, x - dragging.value.x))

  picked.value.to = [top + dy, left + dx, bottom + dy, right + dx].map(Math.round) as Box
  dragging.value = { x, y }
}

const drop = () => {
  if (!dragging.value) return

  dragging.value = null

  if (!moved.value) picked.value = null
}

const confirm = () => {
  if (props.following > 0) {
    asking.value = true

    return
  }

  send(false)
}

const send = (following: boolean) => {
  if (!picked.value) return

  asking.value = false
  move.name = picked.value.name
  move.box = picked.value.to
  move.following = following
  move.post(props.moveUrl, { preserveScroll: true, onSuccess: reset })
}

watch(
  () => props.disabled,
  (disabled) => {
    if (!disabled) return

    reset()
    mode.value = 'select'
  },
)

const onKey = (event: KeyboardEvent) => {
  if (event.key !== 'Escape' || asking.value) return

  if (picked.value) {
    reset()
  } else {
    selected.value = null
  }
}

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  selected.value = null
})
</script>
