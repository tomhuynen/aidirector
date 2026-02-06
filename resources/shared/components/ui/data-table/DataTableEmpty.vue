<template>
  <div
    class="flex flex-col items-center justify-center rounded-md border border-dashed p-8 text-center"
    v-bind="config?.dataAttributes"
  >
    <!-- Icon (shown by default, hidden when icon === false) -->
    <div v-if="config?.icon !== false" class="mx-auto flex size-12 items-center justify-center rounded-full bg-muted">
      <component :is="iconComponent" class="size-6 text-muted-foreground" />
    </div>

    <h3 class="mt-4 text-lg font-semibold">{{ config?.title ?? trans('No results') }}</h3>

    <p v-if="config?.message" class="mt-2 text-sm text-muted-foreground">
      {{ config.message }}
    </p>

    <!-- Actions -->
    <div v-if="visibleActions.length" class="mt-4 flex flex-wrap items-center justify-center gap-2">
      <Button
        v-for="(action, index) in visibleActions"
        :key="index"
        :variant="getButtonVariant(action.variant)"
        :disabled="action.url.disabled"
        :class="action.buttonClass"
        v-bind="action.dataAttributes"
        @click="handleAction(action)"
      >
        <component :is="getIconComponent(action.icon)" v-if="action.icon" class="mr-2 size-4" />
        {{ action.label }}
      </Button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { visitModal } from '@inertiaui/modal-vue'
import { visitUrl } from '@inertiaui/table-vue'
import { Button } from '@shared:ui/button'
import { trans } from 'laravel-vue-i18n'
import { Inbox } from 'lucide-vue-next'
import { computed } from 'vue'

import { useIcons } from './composables/useIcons'
import type { EmptyStateAction, EmptyStateConfig } from './types'

const { getIconComponent } = useIcons()

const props = defineProps<{
  config?: EmptyStateConfig
}>()

// Main icon component
const iconComponent = computed(() => {
  if (props.config?.icon === true || props.config?.icon === undefined) {
    return Inbox
  }
  if (typeof props.config?.icon === 'string') {
    return getIconComponent(props.config.icon) ?? Inbox
  }
  return Inbox
})

// Filter out hidden actions
const visibleActions = computed(() => (props.config?.actions ?? []).filter((action) => !action.url.hidden))

// Map variant to shadcn Button variant
const getButtonVariant = (variant?: string): 'default' | 'destructive' | 'outline' | 'secondary' | 'ghost' | 'link' => {
  switch (variant) {
    case 'danger':
      return 'destructive'
    case 'info':
    case 'success':
    case 'warning':
    default:
      return 'default'
  }
}

// Handle action click
const handleAction = (action: EmptyStateAction) => {
  if (action.url.asDownload) {
    // For downloads, create a link and click it
    const link = document.createElement('a')
    link.href = action.url.url
    link.download = typeof action.url.asDownload === 'string' ? action.url.asDownload : ''
    link.click()
  } else if (action.url.modal) {
    // Use visitModal for modal URLs
    visitModal(action.url.url, action.url.modal === true ? {} : action.url.modal)
  } else {
    // Use visitUrl for navigation
    visitUrl(action.url)
  }
}
</script>
