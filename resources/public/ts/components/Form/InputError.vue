<template>
  <p v-if="message" class="text-sm text-destructive">{{ message }}</p>
</template>
<script setup lang="ts">
defineProps<{
  message?: string
}>()
</script>
