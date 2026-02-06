<!-- @fallthroughAttributes true -->
<!-- @strictTemplates true -->

<template>
  <optgroup data-slot="native-select-optgroup" :class="cn('bg-popover text-popover-foreground', props.class)">
    <slot />
  </optgroup>
</template>

<script setup lang="ts">
import { cn } from '@shared/lib/utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{ class?: HTMLAttributes['class'] }>()
</script>
