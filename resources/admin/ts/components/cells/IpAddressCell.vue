<template>
  <span class="flex items-center gap-1">
    <span v-if="value.bogon" class="text-sm">🌏</span>
    <span v-else class="text-sm" :title="value.countryCode">{{ value.countryFlag }}</span>
    <code class="text-muted-foreground" :title="value.organization">{{ value.value }}</code>
  </span>
</template>

<script setup lang="ts">
interface IpAddressValue {
  value: string
  bogon?: boolean
  countryCode?: string
  countryFlag?: string
  organization?: string
}

defineProps<{
  value: IpAddressValue
}>()
</script>
