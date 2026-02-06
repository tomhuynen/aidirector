<template>
  <Badge v-if="value && value.value" :variant="getVariant(value.variant)">
    {{ value.value }}
  </Badge>
</template>

<script setup lang="ts">
import type { BadgeVariants } from '@shared:ui/badge'
import { Badge } from '@shared:ui/badge'

import type { BadgeValue } from './types'

defineProps<{
  value: BadgeValue | null | undefined
}>()

const getVariant = (variant?: string): BadgeVariants['variant'] => {
  switch (variant) {
    case 'danger':
      return 'destructive'
    case 'default':
      return 'outline'
    case 'success':
      return 'success'
    case 'warning':
      return 'secondary'
    default:
      return 'outline'
  }
}
</script>
