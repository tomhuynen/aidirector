<template>
  <relative-time :datetime="value">{{ value }}</relative-time>
</template>

<script setup lang="ts">
defineProps<{
  value: string
}>()
</script>
