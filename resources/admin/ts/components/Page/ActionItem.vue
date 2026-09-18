<template>
  <!-- Inline button -->
  <template v-if="inline">
    <Button v-if="href" variant="outline" size="sm" :class="inlineClass" as-child>
      <Link :href="href" :aria-disabled="disabled || undefined" :tabindex="disabled ? -1 : undefined">
        <component :is="iconComponent" v-if="iconComponent" />
        <slot>{{ title }}</slot>
      </Link>
    </Button>
    <Button
      v-else
      type="button"
      variant="outline"
      size="sm"
      :class="inlineClass"
      :disabled="disabled"
      @click="$emit('click')"
    >
      <component :is="iconComponent" v-if="iconComponent" />
      <slot>{{ title }}</slot>
    </Button>
  </template>

  <!-- Dropdown menu item -->
  <DropdownMenuItem v-else :disabled="disabled" as-child>
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
import { pageActionsInlineKey } from '@admin:components/Page/ActionsContext.vue'
import { Link } from '@inertiajs/vue3'
import { Button } from '@shared:ui/button'
import { DropdownMenuItem } from '@shared:ui/dropdown-menu'
import * as icons from 'lucide-vue-next'
import { computed, inject, ref } from 'vue'

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

const inline = inject(pageActionsInlineKey, ref(false))

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

const inlineClass = computed(() => [
  props.variant === 'destructive' ? 'text-destructive hover:text-destructive' : '',
  props.disabled && props.href ? 'pointer-events-none opacity-50' : '',
])
</script>
