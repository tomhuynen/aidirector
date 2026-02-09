<template>
  <div
    ref="tableWrapperRef"
    class="relative space-y-4"
    :class="{ 'opacity-50 pointer-events-none': actions.isPerformingAction.value }"
  >
    <slot name="loading" :table="table" :actions="actions">
      <div
        v-if="actions.isPerformingAction.value"
        class="absolute inset-0 z-50 flex items-center justify-center bg-background/50"
      >
        <Loader2 class="size-6 animate-spin text-muted-foreground" />
      </div>
    </slot>

    <slot
      v-if="resource.emptyState && (!resource.results.data.length || resource.emptyState !== true)"
      name="empty-state"
      :table="table"
    >
      <DataTableEmpty
        v-if="!resource.results.data.length"
        :config="typeof resource.emptyState === 'object' ? resource.emptyState : undefined"
      />
    </slot>

    <template v-else>
      <slot name="toolbar" :table="table" :actions="actions">
        <DataTableToolbar :resource="resource" :table="table" :actions="actions" />
      </slot>

      <slot name="filters" :table="table" :actions="actions">
        <DataTableFilters v-if="table.hasFilters.value" :resource="resource" :table="table" />
      </slot>

      <div class="rounded-md border" :class="{ 'opacity-50': table.isNavigating.value }">
        <div ref="tableContainerRef" class="relative w-full overflow-x-auto">
          <table class="w-full caption-bottom text-sm">
            <slot name="header" :table="table" :actions="actions">
              <DataTableHeader :resource="resource" :table="table" :actions="actions">
                <template
                  v-for="column in resource.columns"
                  :key="column.attribute"
                  #[`header(${column.attribute})`]="headerProps"
                >
                  <slot :name="`header(${column.attribute})`" v-bind="headerProps" />
                </template>
              </DataTableHeader>
            </slot>

            <slot name="body" :table="table" :actions="actions">
              <DataTableBody
                :resource="resource"
                :table="table"
                :actions="actions"
                :on-row-click="onRowClick"
                @custom-action="emit('custom-action', $event)"
              >
                <template
                  v-for="column in resource.columns"
                  :key="column.attribute"
                  #[`cell(${column.attribute})`]="cellProps"
                >
                  <slot :name="`cell(${column.attribute})`" v-bind="cellProps" />
                </template>
              </DataTableBody>
            </slot>
          </table>
        </div>
      </div>

      <slot name="footer" :table="table" :actions="actions">
        <DataTablePagination
          v-if="table.hasSelectableRows.value || resource.pagination"
          :resource="resource"
          :table="table"
          :actions="actions"
          @paginate="(url) => table.visitPaginationUrl(url, scrollToTopOfTable)"
        />
      </slot>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useActions, useTable } from '@inertiaui/table-vue'
import { Loader2 } from 'lucide-vue-next'
import { ref } from 'vue'

import DataTableBody from './DataTableBody.vue'
import DataTableEmpty from './DataTableEmpty.vue'
import DataTableFilters from './DataTableFilters.vue'
import DataTableHeader from './DataTableHeader.vue'
import DataTablePagination from './DataTablePagination.vue'
import DataTableToolbar from './DataTableToolbar.vue'
import type { TableAction, TableColumn, TableResource, TableRow } from './types'

const props = defineProps<{
  resource: TableResource
  onRowClick?: (item: TableRow, column: TableColumn) => void
}>()

const emit = defineEmits<{
  'custom-action': [payload: { action: TableAction; keys: (string | number)[]; onFinish?: () => void }]
}>()

const tableWrapperRef = ref<HTMLElement | null>(null)
const tableContainerRef = ref<HTMLElement | null>(null)

const table = useTable(props.resource)
const actions = useActions()

const scrollToTopOfTable = () => {
  if (tableWrapperRef.value) {
    window.scrollTo({
      top: tableWrapperRef.value.offsetTop - 16,
      behavior: 'instant',
    })
  }
}

defineExpose({
  table,
  actions,
})
</script>
