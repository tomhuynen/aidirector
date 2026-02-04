<template>
  <div
    data-slot="card"
    :class="cn('bg-card text-card-foreground flex flex-col gap-6 rounded-lg border py-6 shadow-sm', props.class)"
  >
    <slot />
  </div>
</template>

<script setup lang="ts">
import { cn } from '@shared/lib/utils'
import type { HTMLAttributes } from 'vue'

const props = defineProps<{
  class?: HTMLAttributes['class']
}>()
</script>
