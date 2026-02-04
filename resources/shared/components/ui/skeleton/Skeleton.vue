<template>
  <div data-slot="skeleton" :class="cn('animate-pulse rounded-md bg-primary/10', props.class)" />
</template>

<script setup lang="ts">
import { cn } from '@shared/lib/utils'
import type { HTMLAttributes } from 'vue'

interface SkeletonProps {
  class?: HTMLAttributes['class']
}

const props = defineProps<SkeletonProps>()
</script>
