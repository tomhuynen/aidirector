<template>
  <Badge v-if="value && value.value" :variant="getVariant(value.variant)">
    {{ value.value }}
  </Badge>
</template>

<script setup lang="ts">
import type { BadgeVariants } from '@shared:ui/badge'
import { Badge } from '@shared:ui/badge'

import type { BadgeData, ButtonVariant } from './types'

defineProps<{
  value: BadgeData | null | undefined
}>()

const getVariant = (variant?: ButtonVariant): BadgeVariants['variant'] => {
  switch (variant) {
    case 'danger':
      return 'destructive'
    case 'success':
      return 'success'
    case 'warning':
      return 'secondary'
    case 'info':
    case 'default':
    default:
      return 'outline'
  }
}
</script>
