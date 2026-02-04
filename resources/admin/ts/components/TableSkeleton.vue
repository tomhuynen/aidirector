<template>
  <div class="space-y-2">
    <div class="flex items-center gap-2">
      <Skeleton class="size-6 rounded-full flex-none" />
      <Skeleton class="h-6 flex-1" />
    </div>
    <div class="flex items-center gap-2">
      <Skeleton class="size-6 rounded-full flex-none" />
      <Skeleton class="h-6 flex-1" />
    </div>
    <div class="flex items-center gap-2">
      <Skeleton class="size-6 rounded-full flex-none" />
      <Skeleton class="h-6 flex-1" />
    </div>
  </div>
</template>
<script lang="ts" setup>
import { Skeleton } from '@shared:ui/skeleton'
</script>
