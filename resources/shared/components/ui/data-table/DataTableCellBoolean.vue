<template>
  <component :is="iconComponent" class="size-5" :class="value ? 'text-green-600' : 'text-red-600'" />
</template>

<script setup lang="ts">
import { Check, X } from 'lucide-vue-next'
import { computed } from 'vue'

import { useIcons } from './composables/useIcons'
import type { TableColumn } from './types'

const { getIconComponent } = useIcons()

const props = defineProps<{
  value: boolean
  column: TableColumn
}>()

const iconComponent = computed(() => {
  if (props.value) {
    return getIconComponent(props.column.trueIcon) ?? Check
  }
  return getIconComponent(props.column.falseIcon) ?? X
})
</script>
