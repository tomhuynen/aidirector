<template>
  <h3 data-slot="card-title" :class="cn('leading-none font-semibold', props.class)">
    <slot />
  </h3>
</template>

<script setup lang="ts">
import { cn } from '@shared/lib/utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>
