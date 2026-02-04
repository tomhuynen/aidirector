<template>
  <component :is="icon" :size="size" :color="color" :stroke-width="strokeWidth" :default-class="defaultClass" />
</template>
<script setup lang="ts">
import { useIcons } from '@admin:composables/icons'
import { type Component, computed } from 'vue'

const { resolve } = useIcons()

const props = withDefaults(
  defineProps<{
    name: string
    size?: number
    color?: string
    strokeWidth?: number
    defaultClass?: string
  }>(),
  {
    size: 16,
    color: 'currentColor',
    strokeWidth: 2,
    defaultClass: undefined,
  },
)

const icon = computed(() => resolve(props.name) as Component)
</script>
