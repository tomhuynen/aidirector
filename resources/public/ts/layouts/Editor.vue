<template>
  <div class="flex h-svh flex-col overflow-hidden bg-paper-deep/60">
    <slot />
  </div>
  <Toaster position="bottom-right" />
</template>
<script setup lang="ts">
import { Toaster } from 'vue-sonner'
</script>
