<template>
  <header class="flex h-12 shrink-0 items-center gap-2 border-b px-4">
    <SidebarTrigger class="-ml-1" />
    <Separator orientation="vertical" class="mr-2 h-4" />

    <!-- Breadcrumbs -->
    <Breadcrumbs :breadcrumbs="breadcrumbs" />

    <!-- Actions dropdown -->
    <PageActions v-if="hasActions">
      <template #prepend>
        <slot name="actions-prepend" />
      </template>

      <template #default>
        <PageActionItem
          v-for="action in serverActions"
          :key="action.title"
          :title="action.title"
          :href="action.action"
          :icon="action.icon"
          :disabled="action.disabled"
        />
      </template>

      <template #append>
        <slot name="actions-append" />
      </template>
    </PageActions>
  </header>

  <div class="flex flex-1 flex-col gap-4 p-4">
    <Heading v-if="title" :title="title" :description="description" />
    <slot />
  </div>
</template>

<script setup lang="ts">
import Breadcrumbs from '@admin:components/Breadcrumbs.vue'
import Heading from '@admin:components/Heading.vue'
import PageActionItem from '@admin:components/Page/ActionItem.vue'
import PageActions from '@admin:components/Page/Actions.vue'
import { usePage } from '@admin:composables/page'
import { Separator } from '@shared:ui/separator'
import { SidebarTrigger } from '@shared:ui/sidebar'
import { computed, useSlots } from 'vue'

defineProps<{
  title?: string
  description?: string
}>()

const slots = useSlots()
const { breadcrumbs, pageActions: serverActions } = usePage()

const hasActions = computed(() => {
  return serverActions.value.length > 0 || slots['actions-prepend'] || slots['actions-append']
})
</script>
