<template>
  <div
    v-if="
      resource.hasBulkActions ||
      resource.hasSearch ||
      resource.hasExports ||
      resource.hasFilters ||
      resource.hasToggleableColumns ||
      resource.views
    "
    class="flex flex-col justify-between gap-4 md:flex-row md:items-center"
  >
    <Input
      v-if="resource.hasSearch"
      v-model="searchQuery"
      class="w-full md:max-w-sm"
      :placeholder="trans('Search...')"
      :autofocus="!!resource.autofocus"
    />

    <div class="flex items-center gap-2">
      <DataTableBulkActions
        v-if="resource.hasBulkActions || resource.hasExports"
        :resource="resource"
        :actions="actions"
        @action-success="emit('action-success', $event)"
        @custom-action="emit('custom-action', $event)"
      />

      <DataTableAddFilter v-if="resource.hasFilters" :resource="resource" :table="table" />

      <DataTableViewsDropdown
        v-if="resource.views"
        :views="resource.views"
        :current-state="resource.state"
        @view-selected="(view) => table.putState(view.state)"
      />

      <DataTableColumnToggle v-if="resource.hasToggleableColumns" :resource="resource" :table="table" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { Input } from '@shared:ui/input'
import { trans } from 'laravel-vue-i18n'
import { computed } from 'vue'

import DataTableAddFilter from './DataTableAddFilter.vue'
import DataTableBulkActions from './DataTableBulkActions.vue'
import DataTableColumnToggle from './DataTableColumnToggle.vue'
import DataTableViewsDropdown from './DataTableViewsDropdown.vue'
import type { TableResource, UseActionsReturn, UseTableReturn } from './types'

const props = defineProps<{
  resource: TableResource
  table: UseTableReturn
  actions: UseActionsReturn
}>()

const searchQuery = computed({
  get: () => props.table.state.value.search ?? '',
  set: (value: string) => {
    // eslint-disable-next-line vue/no-mutating-props -- table.state is a Ref from useTable composable, mutation is intentional
    props.table.state.value.search = value
  },
})

const emit = defineEmits<{
  'action-success': [result: unknown]
  'custom-action': [action: unknown]
}>()
</script>
