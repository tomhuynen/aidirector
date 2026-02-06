<template>
  <span class="flex items-center gap-2">
    <Icon :name="value.deviceTypeIcon" />
    <span v-if="value.isBot">
      <div class="truncate" :title="value.value">{{ value.value }}</div>
    </span>
    <span v-else :title="value.value">
      {{ value.clientFamily }} {{ value.clientVersion }} @ {{ value.osName }} {{ value.osVersion }}
    </span>
  </span>
</template>

<script setup lang="ts">
import Icon from '@admin:components/Icon.vue'

interface UserAgentValue {
  value: string
  deviceTypeIcon: string
  isBot?: boolean
  clientFamily?: string
  clientVersion?: string
  osName?: string
  osVersion?: string
}

defineProps<{
  value: UserAgentValue
}>()
</script>
