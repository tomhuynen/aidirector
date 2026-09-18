<template>
  <slot />
</template>

<script lang="ts">
import type { InjectionKey, Ref } from 'vue'

/**
 * Tells PageActionItem whether it is rendered inline (as a button) or inside the dropdown menu.
 */
export const pageActionsInlineKey: InjectionKey<Ref<boolean>> = Symbol('pageActionsInline')
</script>

<script setup lang="ts">
import { provide, toRef } from 'vue'

const props = defineProps<{
  inline: boolean
}>()

provide(pageActionsInlineKey, toRef(props, 'inline'))
</script>
