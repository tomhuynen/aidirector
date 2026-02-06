<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="outline" size="sm" class="h-8">
        <SlidersHorizontal class="mr-2 size-4" />
        {{ trans('View') }}
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-48">
      <DropdownMenuLabel>{{ trans('Toggle columns') }}</DropdownMenuLabel>
      <DropdownMenuSeparator />
      <DropdownMenuItem
        v-for="column in toggleableColumns"
        :key="column.attribute"
        class="gap-2"
        @select.prevent="table.toggleColumn(column)"
      >
        <Check v-if="table.state.value.columns[column.attribute]" class="size-4" />
        <span v-else class="size-4" />
        {{ column.header }}
      </DropdownMenuItem>
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
import { Check, SlidersHorizontal } from 'lucide-vue-next'
import { computed } from 'vue'

import type { TableResource, UseTableReturn } from './types'

const props = defineProps<{
  resource: TableResource
  table: UseTableReturn
}>()

const toggleableColumns = computed(() => props.resource.columns.filter((c) => c.toggleable))
</script>
