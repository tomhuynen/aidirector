<template>
  <ProgressCircle :percentage="percentage" />
</template>

<script lang="ts" setup>
import { useIntervalFn } from '@vueuse/core'
import { ref } from 'vue'

import ProgressCircle from './ProgressCircle.vue'

const percentage = ref(0)
const delta = ref(2)

useIntervalFn(() => {
  percentage.value = percentage.value + delta.value
  if (percentage.value > 90) {
    delta.value = -1
  } else if (percentage.value < 2) {
    delta.value = 2
  }
}, 25)
</script>

<style scoped>
@keyframes spin {
  100% {
    rotate: 360deg;
  }
}

svg {
  animation: spin 0.5s linear infinite;
}
</style>
