<template>
  <div class="flex flex-col justify-between gap-4 px-2 text-sm md:flex-row md:items-center">
    <div v-if="table.hasSelectableRows.value" class="flex-1 text-muted-foreground">
      <span v-if="actions.allItemsAreSelected.value">
        {{
          resource.results.total === 1
            ? trans('1 row selected')
            : trans('All :total rows selected', { total: String(resource.results.total ?? 0) })
        }}
      </span>
      <span v-else-if="actions.selectedItems.value.length === 1">{{ trans('1 row selected') }}</span>
      <span v-else-if="actions.selectedItems.value.length > 1">
        {{
          trans(':count of :total rows selected', {
            count: String(actions.selectedItems.value.length),
            total: String(resource.results.total ?? 0),
          })
        }}
      </span>
      <span v-else>{{ trans('No rows selected') }}</span>
    </div>

    <div v-if="resource.pagination" class="flex items-center gap-6 lg:gap-8">
      <div v-if="resource.perPageOptions?.length" class="flex items-center gap-2">
        <span class="text-sm text-muted-foreground">{{ trans('Rows per page') }}</span>
        <Select :model-value="String(table.state.value.perPage)" @update:model-value="table.setPerPage(Number($event))">
          <SelectTrigger class="h-8 w-[70px]">
            <SelectValue :placeholder="String(table.state.value.perPage)" />
          </SelectTrigger>
          <SelectContent side="top">
            <SelectItem v-for="option in resource.perPageOptions" :key="option" :value="String(option)">
              {{ option }}
            </SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div
        v-if="resource.paginationType === 'full'"
        class="flex w-[100px] items-center justify-center text-sm text-muted-foreground"
      >
        {{
          trans('Page :current of :last', {
            current: String(resource.results.current_page ?? 0),
            last: String(resource.results.last_page ?? 0),
          })
        }}
      </div>

      <div class="flex items-center gap-2">
        <Button
          v-if="resource.paginationType === 'full'"
          variant="outline"
          size="icon"
          class="size-8"
          :disabled="!resource.results.first_page_url || resource.results.current_page === 1"
          @click="emit('paginate', resource.results.first_page_url!)"
        >
          <ChevronsLeft class="size-4" />
        </Button>

        <Button
          variant="outline"
          size="icon"
          class="size-8"
          :disabled="!resource.results.prev_page_url || resource.results.on_first_page"
          @click="emit('paginate', resource.results.prev_page_url!)"
        >
          <ChevronLeft class="size-4" />
        </Button>

        <Button
          variant="outline"
          size="icon"
          class="size-8"
          :disabled="!resource.results.next_page_url || resource.results.on_last_page"
          @click="emit('paginate', resource.results.next_page_url!)"
        >
          <ChevronRight class="size-4" />
        </Button>

        <Button
          v-if="resource.paginationType === 'full'"
          variant="outline"
          size="icon"
          class="size-8"
          :disabled="!resource.results.last_page_url || resource.results.current_page === resource.results.last_page"
          @click="emit('paginate', resource.results.last_page_url!)"
        >
          <ChevronsRight class="size-4" />
        </Button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { Button } from '@shared:ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@shared:ui/select'
import { trans } from 'laravel-vue-i18n'
import { ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight } from 'lucide-vue-next'

import type { TableResource, UseActionsReturn, UseTableReturn } from './types'

defineProps<{
  resource: TableResource
  table: UseTableReturn
  actions: UseActionsReturn
}>()

const emit = defineEmits<{
  paginate: [url: string]
}>()
</script>
