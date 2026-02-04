<template>
  <svg :viewBox="viewBox" :width="size" :height="size" fill="none">
    <circle class="opacity-20 stroke-current" cx="50%" cy="50%" :r="radius" :stroke-width="strokeWidth" />
    <circle
      class="stroke-current"
      cx="50%"
      cy="50%"
      :r="radius"
      :stroke-width="strokeWidth"
      :pathLength="100"
      stroke-dasharray="100 200"
      :stroke-dashoffset="strokeDashoffset"
      stroke-linecap="round"
      transform="rotate(-90)"
      transform-origin="center"
    />
  </svg>
</template>

<script lang="ts" setup>
import { computed, ref, watch } from 'vue'

const props = withDefaults(
  defineProps<{
    percentage: number
    size?: number
    stroke?: number
  }>(),
  {
    size: 40,
    stroke: undefined,
  },
)

const radius = ref(18)
const strokeWidth = computed(() => (props.stroke === undefined ? props.size / 10 : props.stroke))
const strokeDashoffset = computed(() => 100 - props.percentage)
const viewBox = computed(() => `0 0 ${props.size} ${props.size}`)

watch(
  [() => props.size, () => strokeWidth.value],
  () => {
    radius.value = props.size / 2 - strokeWidth.value
  },
  { immediate: true },
)
</script>

<style scoped>
.track {
  opacity: 0.22;
}
</style>
