<template>
  <DropdownMenuItem :disabled="disabled" as-child>
    <Link v-if="href" :href="href" :class="itemClass">
      <component :is="iconComponent" v-if="iconComponent" class="mr-2 h-4 w-4" />
      <slot>{{ title }}</slot>
    </Link>
    <button v-else type="button" class="w-full" :class="itemClass" @click="$emit('click')">
      <component :is="iconComponent" v-if="iconComponent" class="mr-2 h-4 w-4 text-current" />
      <slot>{{ title }}</slot>
    </button>
  </DropdownMenuItem>
</template>

<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { DropdownMenuItem } from '@shared:ui/dropdown-menu'
import * as icons from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
  title?: string
  href?: string
  icon?: string
  disabled?: boolean
  variant?: 'default' | 'destructive'
}>()

defineEmits<{
  click: []
}>()

const iconComponent = computed(() => {
  if (!props.icon) return null
  return (icons as Record<string, unknown>)[props.icon] ?? null
})

const itemClass = computed(() => {
  if (props.variant === 'destructive') {
    return 'text-destructive focus:text-destructive'
  }
  return ''
})
</script>
