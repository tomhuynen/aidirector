<template>
  <div :class="['inline-flex gap-1 rounded-lg bg-muted text-muted-foreground p-1', containerClass]">
    <button
      v-for="{ value, Icon, label } in tabs"
      :key="value"
      type="button"
      :class="[
        'flex items-center rounded-md px-3.5 py-1.5 transition-colors',
        appearance === value ? 'shadow-sm bg-background text-foreground' : 'text-muted-foreground hover:bg-secondary',
      ]"
      @click="updateAppearance(value)"
    >
      <component :is="Icon" class="-ml-1 h-4 w-4" />
      <span class="ml-1.5 text-sm">{{ label }}</span>
    </button>
  </div>
</template>
<script setup lang="ts">
import { useAppearance } from '@admin:composables/appearance'
import { Monitor, Moon, Sun } from 'lucide-vue-next'

interface Props {
  class?: string
}

const { class: containerClass = '' } = defineProps<Props>()

const { appearance, updateAppearance } = useAppearance()

const tabs = [
  { value: 'light', Icon: Sun, label: 'Light' },
  { value: 'dark', Icon: Moon, label: 'Dark' },
  { value: 'system', Icon: Monitor, label: 'System' },
] as const
</script>
