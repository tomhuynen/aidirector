<template>
  <Primitive
    data-slot="control-group-item"
    :as="as"
    :class="
      cn(
        'border-input selection:bg-primary selection:text-primary-foreground dark:bg-input/30 flex min-h-9 cursor-text items-center gap-2 border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none md:text-sm rounded-none focus-within:z-10 -me-px h-auto first:rounded-s-md last:-me-0 last:rounded-e-md',
        props.class,
      )
    "
  >
    <slot />
  </Primitive>
</template>

<script setup lang="ts">
import { cn } from '@shared/lib/utils'
import { Primitive, type PrimitiveProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

interface Props extends PrimitiveProps {
  class?: HTMLAttributes['class']
}

const props = withDefaults(defineProps<Props>(), {
  as: 'div',
  class: undefined,
})
</script>
