<template>
  <Base :id="id" :label="label" :error="error">
    <template #label>
      <slot :id="id" name="label" />
    </template>
    <Popover v-model:open="open">
      <PopoverTrigger as-child>
        <Button variant="outline" role="combobox" :aria-expanded="open" class="w-full justify-between">
          <span v-if="selected" class="truncate">
            <slot name="selected" :item="selected">
              {{ selected.name }}
            </slot>
          </span>
          <span v-else-if="searchable.loading.value" class="flex items-center gap-2">
            <ProgressIndeterminate class="size-4" />
            {{ $t('Loading...') }}
          </span>
          <span v-else class="text-muted-foreground">
            {{ placeholder ?? $t('Select an option') }}
          </span>
          <ChevronsUpDownIcon class="ml-2 h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent class="w-[--reka-popover-trigger-width] p-0" align="start">
        <Command :should-filter="false">
          <div class="flex h-9 items-center gap-2 border-b px-3">
            <ProgressIndeterminate v-if="searchable.loading.value" class="size-4" />
            <SearchIcon v-else class="size-4 shrink-0 opacity-50" />
            <input
              v-model="searchable.search.value"
              type="text"
              :placeholder="(searchPlaceholder ?? isLoadingMore) ? $t('Loading...') : $t('Search...')"
              class="placeholder:text-muted-foreground flex h-10 w-full rounded-md bg-transparent py-3 text-sm outline-none disabled:cursor-not-allowed disabled:opacity-50"
            />
          </div>
          <CommandList ref="listRef">
            <CommandGroup v-if="searchable.loading.value && searchable.items.value.length === 0">
              <CommandItem value="loading" disabled>
                {{ $t('Loading...') }}
              </CommandItem>
            </CommandGroup>

            <CommandGroup v-else-if="searchable.items.value.length === 0">
              <CommandItem value="empty" disabled>
                {{ emptyText ?? $t('No results found') }}
              </CommandItem>
            </CommandGroup>

            <CommandGroup v-if="searchable.items.value.length > 0">
              <CommandItem
                v-for="item in searchable.items.value"
                :key="item.id"
                :value="item.id"
                @select="onSelect(item)"
              >
                <slot name="item" :item="item">
                  {{ item.name }}
                </slot>
                <CheckIcon :class="cn('ml-auto h-4 w-4', selected?.id === item.id ? 'opacity-100' : 'opacity-0')" />
              </CommandItem>
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
    <template #help>
      <slot name="help" />
    </template>
  </Base>
</template>

<script setup lang="ts" generic="T extends SearchResult, V = T">
import { useSearchable } from '@admin/ts/composables/searchable'
import { $t } from '@admin:shared/i18n'
import type { SearchResult } from '@admin:types/utils'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Command, CommandGroup, CommandItem, CommandList } from '@shared:ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@shared:ui/popover'
import { useInfiniteScroll } from '@vueuse/core'
import { CheckIcon, ChevronsUpDownIcon, SearchIcon } from 'lucide-vue-next'
import { computed, onMounted, ref, useId, watch } from 'vue'

import ProgressIndeterminate from '../ProgressIndeterminate.vue'
import type { BaseInputProps } from './Base.vue'
import Base from './Base.vue'

const id = useId()
const open = ref(false)
const listRef = ref<InstanceType<typeof CommandList> | null>(null)
const initialized = ref(false)
const resolving = ref(false)

type Props = Omit<BaseInputProps, 'id'> & {
  entity: string
  placeholder?: string
  emptyText?: string
  searchPlaceholder?: string
  valueKey?: keyof T // e.g., 'id' to emit only the ID
}

const props = defineProps<Props>()

defineOptions({ inheritAttrs: false })

// The external v-model value (could be ID or full object)
const modelValue = defineModel<V | undefined>()

// Internal selected object (always the full object for display)
const selected = ref<T | undefined>()

const searchable = useSearchable<T>({ entity: props.entity })

// Track if we're loading more (not initial load)
const isLoadingMore = computed(() => searchable.loading.value && searchable.items.value.length > 0)

// Lazy load: fetch when popover opens for the first time
watch(open, (isOpen) => {
  if (isOpen && !initialized.value) {
    initialized.value = true
    searchable.refresh()
  }
})

// Handle selection
const onSelect = (item: T) => {
  const isDeselecting = selected.value?.id === item.id

  selected.value = isDeselecting ? undefined : item

  // Emit based on valueKey
  if (props.valueKey) {
    modelValue.value = (isDeselecting ? undefined : item[props.valueKey]) as V
  } else {
    modelValue.value = (isDeselecting ? undefined : item) as V
  }

  open.value = false
}

const resolveValue = async () => {
  if (!modelValue.value) {
    selected.value = undefined
    return
  }

  // If it's already a full object with expected shape, use it directly
  if (typeof modelValue.value === 'object' && 'name' in modelValue.value) {
    selected.value = modelValue.value as unknown as T
    return
  }

  // It's an ID - fetch the single record
  resolving.value = true
  const result = await searchable.fetchSingle(modelValue.value as string | number)
  if (result) {
    selected.value = result
  }
  resolving.value = false
}

// Watch for external modelValue changes
watch(
  modelValue,
  (newValue, oldValue) => {
    if (JSON.stringify(newValue) === JSON.stringify(oldValue)) {
      return
    }

    resolveValue()
  },
  { immediate: false },
)

// Resolve on mount
onMounted(() => {
  resolveValue()
})

// Infinite scroll using VueUse
useInfiniteScroll(
  () => listRef.value?.$el,
  () => {
    searchable.loadMore()
  },
  {
    distance: 50,
    canLoadMore: () => searchable.hasMore.value && !searchable.loading.value,
  },
)
</script>
