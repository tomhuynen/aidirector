<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="outline" size="sm" class="h-8">
        <ListFilter class="mr-2 size-4" />
        {{ trans('Filter') }}
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-48">
      <DropdownMenuLabel>{{ trans('Add filter') }}</DropdownMenuLabel>
      <DropdownMenuSeparator />
      <DropdownMenuItem v-for="filter in availableFilters" :key="filter.attribute" @select="table.addFilter(filter)">
        {{ filter.label }}
      </DropdownMenuItem>
      <div v-if="!availableFilters.length" class="px-2 py-1.5 text-sm text-muted-foreground">
        {{ trans('No filters available') }}
      </div>
    </DropdownMenuContent>
  </DropdownMenu>
</template>

<script setup lang="ts">
import { Button } from '@shared:ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@shared:ui/dropdown-menu'
import { trans } from 'laravel-vue-i18n'
import { ListFilter } from 'lucide-vue-next'
import { computed } from 'vue'

import type { TableResource, UseTableReturn } from './types'

const props = defineProps<{
  resource: TableResource
  table: UseTableReturn
}>()

const availableFilters = computed(() =>
  props.resource.filters.filter((filter) => !props.table.state.value.filters[filter.attribute]?.enabled),
)
</script>
