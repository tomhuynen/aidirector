<template>
  <tbody class="[&_tr:last-child]:border-0">
    <tr v-if="!resource.results.data.length">
      <td :colspan="columnCount" class="h-24 text-center text-muted-foreground">{{ trans('No results found.') }}</td>
    </tr>

    <tr
      v-for="(item, itemIndex) in resource.results.data"
      :key="getRowKey(item, itemIndex)"
      class="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted"
      :data-state="isRowSelected(item) ? 'selected' : undefined"
      v-bind="item._data_attributes"
    >
      <td
        v-if="actions.selectedItems && table.hasSelectableRows.value"
        class="w-10 cursor-pointer px-2 text-center align-middle"
        :class="{ 'pointer-events-none opacity-50': item._is_selectable === false }"
        @click="
          item._is_selectable !== false && item._primary_key !== undefined && actions.toggleItem(item._primary_key)
        "
      >
        <Checkbox :model-value="isRowSelected(item)" class="pointer-events-none" />
      </td>

      <td
        v-for="column in visibleColumns"
        :key="column.attribute"
        class="p-2 align-middle first:pl-4 last:pr-4 [&:has([role=checkbox])]:pr-0"
        :class="[
          getCellClasses(column),
          { 'cursor-pointer': isClickable(item, column), 'w-px': column.type === 'image' },
        ]"
        @click="handleCellClick(item, column, $event)"
      >
        <div class="flex items-center" :class="getAlignmentClass(column.alignment)">
          <slot
            :name="`cell(${column.attribute})`"
            :item="item"
            :column="column"
            :value="item[column.attribute]"
            :table="table"
            :actions="actions"
          >
            <DataTableRowActions
              v-if="column.attribute === '_actions'"
              :item="item"
              :column="column"
              :resource="resource"
              :actions="actions"
              @custom-action="emit('custom-action', $event)"
            />
            <DataTableCellBadge v-else-if="column.type === 'badge'" :value="item[column.attribute] as BadgeData" />
            <DataTableCellBoolean
              v-else-if="column.type === 'boolean'"
              :value="toBooleanValue(item[column.attribute])"
              :column="column"
            />
            <DataTableCellImage v-else-if="column.type === 'image'" :value="item._column_images?.[column.attribute]" />
            <ul v-else-if="Array.isArray(item[column.attribute])" :class="getTruncateClass(column)">
              <li
                v-for="(value, index) in typeof column.truncate === 'number'
                  ? (item[column.attribute] as unknown[]).slice(0, column.truncate)
                  : (item[column.attribute] as unknown[])"
                :key="index"
              >
                <template
                  v-if="
                    typeof column.truncate === 'number' &&
                    (item[column.attribute] as unknown[]).length > column.truncate &&
                    index === column.truncate - 1
                  "
                >
                  {{ value }}...
                </template>
                <template v-else>{{ value }}</template>
              </li>
            </ul>
            <span v-else :class="getTruncateClass(column)">{{ item[column.attribute] }}</span>
          </slot>
        </div>
      </td>
    </tr>
  </tbody>
</template>

<script setup lang="ts" generic="T extends Record<string, any> = Record<string, any>">
import { visitModal } from '@inertiaui/modal-vue'
import { getClickableColumn, visitUrl } from '@inertiaui/table-vue'
import { Checkbox } from '@shared:ui/checkbox'
import { trans } from 'laravel-vue-i18n'
import { computed } from 'vue'

import DataTableCellBadge from './DataTableCellBadge.vue'
import DataTableCellBoolean from './DataTableCellBoolean.vue'
import DataTableCellImage from './DataTableCellImage.vue'
import DataTableRowActions from './DataTableRowActions.vue'
import type {
  BadgeData,
  CellSlotProps,
  TableAction,
  TableColumn,
  TableItem,
  TableResource,
  UseActionsReturn,
  UseTableReturn,
} from './types'

const props = defineProps<{
  resource: TableResource<T>
  table: UseTableReturn
  actions: UseActionsReturn
  onRowClick?: (item: TableItem<T>, column: TableColumn) => void
}>()

defineSlots<{
  [key: `cell(${string})`]: (props: CellSlotProps<T, UseTableReturn, UseActionsReturn>) => unknown
}>()

const emit = defineEmits<{
  'custom-action': [payload: { action: TableAction; keys: (string | number)[]; onFinish?: () => void }]
}>()

const visibleColumns = computed(() =>
  props.resource.columns.filter((column) => props.table.state.value.columns[column.attribute]),
)

const columnCount = computed(() => {
  let count = visibleColumns.value.length
  if (props.table.hasSelectableRows.value) count++
  return count
})

const getRowKey = (item: TableItem<T>, index: number): string | number =>
  props.table.hasSelectableRows.value && item._primary_key !== undefined ? item._primary_key : index

const isRowSelected = (item: TableItem<T>): boolean =>
  (item._primary_key !== undefined && props.actions.selectedItems.value.includes(item._primary_key)) ||
  (item._is_selectable !== false && props.actions.allItemsAreSelected.value)

const getAlignmentClass = (alignment: TableColumn['alignment']) => ({
  'justify-start': alignment === 'left',
  'justify-center': alignment === 'center',
  'justify-end': alignment === 'right',
})

const getCellClasses = (column: TableColumn) => [
  column.cellClass,
  { 'whitespace-normal': column.wrap, 'whitespace-pre': !column.wrap },
]

const getTruncateClass = (column: TableColumn) =>
  typeof column.truncate === 'number' ? `line-clamp-${column.truncate}` : ''

// Handle various falsy values from server (string "0", "false", "No", etc.)
const toBooleanValue = (value: unknown): boolean => {
  if (
    value === false ||
    value === 0 ||
    value === '0' ||
    value === 'false' ||
    value === 'No' ||
    value === 'no' ||
    value === null ||
    value === undefined
  ) {
    return false
  }
  return Boolean(value)
}

const isClickable = (item: TableItem<T>, column: TableColumn): boolean => {
  if (column.attribute === '_actions') return false
  return !!(props.onRowClick || getClickableColumn(column, item))
}

const handleCellClick = (item: TableItem<T>, column: TableColumn, event: MouseEvent) => {
  if (column.attribute === '_actions') return

  // Check for clickable column URL first
  const clickableUrl = getClickableColumn(column, item, event)
  if (clickableUrl) {
    if (clickableUrl.modal) {
      visitModal(clickableUrl.url, clickableUrl.modal === true ? {} : clickableUrl.modal)
    } else {
      visitUrl(clickableUrl)
    }
    return
  }

  // Fall back to onRowClick prop
  props.onRowClick?.(item, column)
}
</script>
