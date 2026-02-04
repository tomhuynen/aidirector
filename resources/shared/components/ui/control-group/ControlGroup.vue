<template>
  <Primitive
    data-slot="control-group"
    :as="as"
    :class="cn('flex w-full has-disabled:cursor-not-allowed has-disabled:opacity-50', props.class)"
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
