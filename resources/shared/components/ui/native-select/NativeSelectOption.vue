<!-- @fallthroughAttributes true -->
<!-- @strictTemplates true -->

<template>
  <option data-slot="native-select-option" :class="cn('bg-popover text-popover-foreground', props.class)">
    <slot />
  </option>
</template>

<script setup lang="ts">
import { cn } from '@shared/lib/utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{ class?: HTMLAttributes['class'] }>()
</script>
