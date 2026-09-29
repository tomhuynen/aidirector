<template>
  <Badge variant="outline" :class="['gap-1.5 font-normal', tone]">
    <span class="size-1.5 rounded-full bg-current" />
    {{ label }}
  </Badge>
</template>
<script setup lang="ts">
import { Badge } from '@shared:ui/badge'
import { computed } from 'vue'

const props = defineProps<{
  status: string
  label: string
}>()

const tone = computed(() => {
  switch (props.status) {
    case 'video-ready':
      return 'border-emerald-200 text-emerald-700'
    case 'keyframes-ready':
    case 'planned':
    case 'options-ready':
      return 'border-signal/40 text-signal'
    default:
      return 'text-muted-foreground'
  }
})
</script>
