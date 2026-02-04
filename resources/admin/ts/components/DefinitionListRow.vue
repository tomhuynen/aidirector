<template>
  <div class="px-6 py-4 sm:grid sm:grid-cols-5">
    <dt class="text-sm/6 font-medium text-muted-foreground sm:col-span-2">
      {{ label }}
    </dt>
    <dd class="mt-1 text-sm/6 sm:mt-0 sm:col-span-3">
      <slot />
    </dd>
  </div>
</template>
<script setup lang="ts">
defineProps<{
  label: string
}>()
</script>
