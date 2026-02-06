<template>
  <div class="flex flex-wrap items-center gap-2">
    <template v-for="filter in resource.filters" :key="filter.attribute">
      <DataTableFilterBadge
        v-if="table.state.value.filters[filter.attribute]?.enabled"
        v-model="table.state.value.filters[filter.attribute] as any"
        :filter="filter"
        @remove="table.removeFilter(filter as any)"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import DataTableFilterBadge from './DataTableFilterBadge.vue'
import type { TableResource, UseTableReturn } from './types'

defineProps<{
  resource: TableResource
  table: UseTableReturn
}>()
</script>
