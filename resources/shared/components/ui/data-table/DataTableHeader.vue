<template>
  <thead v-if="resource.results.data.length" class="[&_tr]:border-b">
    <tr class="border-b transition-colors hover:bg-muted/50">
      <th
        v-if="actions.selectedItems && table.hasSelectableRows.value"
        class="h-10 w-10 cursor-pointer px-2 text-center align-middle"
        @click="actions.toggleItem('*')"
      >
        <Checkbox :model-value="actions.allItemsAreSelected.value" class="pointer-events-none" />
      </th>

      <th
        v-for="column in visibleColumns"
        :key="column.attribute"
        class="h-10 px-2 text-left align-middle font-medium text-muted-foreground first:pl-4 last:pr-4 [&:has([role=checkbox])]:pr-0"
        :class="[getAlignmentClass(column.alignment), column.headerClass]"
      >
        <slot :name="`header(${column.attribute})`" :column="column" :table="table" :actions="actions">
          <template v-if="column.sortable">
            <Button
              variant="ghost"
              size="sm"
              class="h-8"
              :class="column.alignment === 'right' ? '-mr-3' : '-ml-3'"
              @click="table.sortByColumn(column)"
            >
              <span v-if="column.alignment === 'right'" class="mr-2 w-4">
                <ArrowUp v-if="table.isSortedByColumn(column) === 'asc'" class="size-4" />
                <ArrowDown v-else-if="table.isSortedByColumn(column) === 'desc'" class="size-4" />
              </span>
              {{ column.header }}
              <span v-if="column.alignment !== 'right'" class="ml-2 w-4">
                <ArrowUp v-if="table.isSortedByColumn(column) === 'asc'" class="size-4" />
                <ArrowDown v-else-if="table.isSortedByColumn(column) === 'desc'" class="size-4" />
              </span>
            </Button>
          </template>
          <span v-else>{{ column.header }}</span>
        </slot>
      </th>
    </tr>
  </thead>
</template>

<script setup lang="ts">
import { Button } from '@shared:ui/button'
import { Checkbox } from '@shared:ui/checkbox'
import { ArrowDown, ArrowUp } from 'lucide-vue-next'
import { computed } from 'vue'

import type { TableColumn, TableResource, UseActionsReturn, UseTableReturn } from './types'

const props = defineProps<{
  resource: TableResource
  table: UseTableReturn
  actions: UseActionsReturn
}>()

const visibleColumns = computed(() =>
  props.resource.columns.filter((column) => props.table.state.value.columns[column.attribute]),
)

const getAlignmentClass = (alignment: TableColumn['alignment']) => ({
  'text-left': alignment === 'left',
  'text-center': alignment === 'center',
  'text-right': alignment === 'right',
})
</script>
