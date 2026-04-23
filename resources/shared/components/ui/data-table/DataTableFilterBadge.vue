<template>
  <Popover :open="isOpen" :modal="true" @update:open="isOpen = $event">
    <PopoverTrigger as-child>
      <Badge variant="secondary" class="cursor-pointer gap-1 pr-1">
        {{ filter.label }}
        <span
          v-if="model.value || filter.type === 'boolean' || model.clause === 'is_set' || model.clause === 'is_not_set'"
          class="font-mono"
        >
          {{ getClauseSymbol(model.clause) }}
        </span>
        <span v-if="model.value" class="italic">{{ displayValue }}</span>
        <button
          type="button"
          class="ml-1 rounded-full outline-none ring-offset-background focus:ring-2 focus:ring-ring focus:ring-offset-2"
          @click.stop="emit('remove')"
        >
          <X class="size-3" />
        </button>
      </Badge>
    </PopoverTrigger>
    <PopoverContent :class="filter.type === 'date' ? 'w-auto p-0' : 'w-60 p-3'" align="start">
      <div class="space-y-2" :class="{ 'p-3': filter.type === 'date' }">
        <!-- Clause selector (when multiple clauses available) -->
        <div v-if="filter.clauses.length > 1" class="flex items-center gap-2">
          <ListFilter class="size-4 shrink-0 text-muted-foreground" />
          <NativeSelect ref="clauseSelectRef" v-model="model.clause" class="w-full">
            <option v-for="clause in filter.clauses" :key="clause" :value="clause">
              {{ getClauseLabel(clause) }}
            </option>
          </NativeSelect>
        </div>

        <!-- Value input (not shown for boolean or is_set/is_not_set clauses) -->
        <div v-if="filter.type !== 'boolean' && model.clause !== 'is_set' && model.clause !== 'is_not_set'">
          <!-- Date range picker for between/not_between -->
          <RangeCalendar v-if="isDateRange" v-model="dateRangeValue" initial-focus :number-of-months="2" />

          <!-- Single date picker -->
          <Calendar
            v-else-if="filter.type === 'date'"
            :model-value="dateValue as any"
            initial-focus
            @update:model-value="(val: any) => onDateChange(val)"
          />

          <!-- Non-date inputs -->
          <div v-else class="flex items-center gap-2">
            <Search class="size-4 shrink-0 text-muted-foreground" />
            <div ref="inputContainerRef" class="flex-1">
              <!-- Text input -->
              <Input v-if="filter.type === 'text'" v-model="textValue" class="w-full" />

              <!-- Numeric input (between clause) -->
              <div
                v-else-if="filter.type === 'numeric' && (model.clause === 'between' || model.clause === 'not_between')"
                class="flex items-center gap-2"
              >
                <Input v-model="betweenValue[0]" type="number" class="w-20" />
                <span class="text-sm text-muted-foreground">{{ trans('and') }}</span>
                <Input v-model="betweenValue[1]" type="number" class="w-20" />
              </div>

              <!-- Numeric input (single value) -->
              <Input v-else-if="filter.type === 'numeric'" v-model="numericValue" type="number" class="w-full" />

              <!-- Set select -->
              <Select
                v-else-if="filter.type === 'set' && filter.options"
                v-model="setSelectValue"
                :multiple="isMultiSelect"
              >
                <SelectTrigger class="w-full">
                  <SelectValue :placeholder="trans('Select...')" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem v-for="option in filter.options" :key="option.value" :value="String(option.value)">
                    {{ option.label }}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>
        </div>
      </div>
    </PopoverContent>
  </Popover>
</template>

<script setup lang="ts">
import type { DateValue } from '@internationalized/date'
import { parseDate } from '@internationalized/date'
import { Badge } from '@shared:ui/badge'
import { Calendar } from '@shared:ui/calendar'
import { Input } from '@shared:ui/input'
import { NativeSelect } from '@shared:ui/native-select'
import { Popover, PopoverContent, PopoverTrigger } from '@shared:ui/popover'
import { RangeCalendar } from '@shared:ui/range-calendar'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@shared:ui/select'
import { trans } from 'laravel-vue-i18n'
import { ListFilter, Search, X } from 'lucide-vue-next'
import type { DateRange } from 'reka-ui'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

import type { FilterValue, FilterValueScalar, TableFilter } from './types'

const props = defineProps<{
  filter: TableFilter
}>()

const model = defineModel<FilterValue>({ required: true })

const emit = defineEmits<{
  remove: []
}>()

// Clear value when component unmounts (like original)
onBeforeUnmount(() => {
  model.value.value = null
})

const isOpen = ref(false)
const clauseSelectRef = ref<InstanceType<typeof NativeSelect> | null>(null)
const inputContainerRef = ref<HTMLElement | null>(null)

// Auto-open when filter is newly added
onMounted(() => {
  if (!model.value.new) {
    return
  }
  // Small delay to let component fully stabilize
  setTimeout(() => {
    isOpen.value = true
  }, 50)
})

// Focus input when popover opens
const focusInput = () => {
  if (props.filter.type === 'boolean') {
    clauseSelectRef.value?.$el?.focus?.()
  } else {
    const el = inputContainerRef.value?.querySelector('input,select') as HTMLElement | null
    el?.focus()
  }
}

// Refocus input when clause changes and no value set
watch(
  () => model.value.clause,
  () => {
    if (!model.value.value) {
      focusInput()
    }
  },
)

// Clause helpers
const clauseSymbols: Record<string, string> = {
  equals: '=',
  not_equals: '≠',
  contains: '~',
  not_contains: '!~',
  starts_with: '^',
  ends_with: '$',
  greater_than: '>',
  greater_than_or_equal: '≥',
  less_than: '<',
  less_than_or_equal: '≤',
  between: '↔',
  not_between: '!↔',
  in: '∈',
  not_in: '∉',
  is_set: '✓',
  is_not_set: '✗',
  is_true: '✓',
  is_false: '✗',
  before: '<',
  after: '>',
  equal_or_before: '≤',
  equal_or_after: '≥',
}

const clauseLabels: Record<string, string> = {
  equals: trans('Equals'),
  not_equals: trans('Not equals'),
  contains: trans('Contains'),
  not_contains: trans('Does not contain'),
  starts_with: trans('Starts with'),
  ends_with: trans('Ends with'),
  greater_than: trans('Greater than'),
  greater_than_or_equal: trans('Greater than or equal'),
  less_than: trans('Less than'),
  less_than_or_equal: trans('Less than or equal'),
  between: trans('Between'),
  not_between: trans('Not between'),
  in: trans('In'),
  not_in: trans('Not in'),
  is_set: trans('Is set'),
  is_not_set: trans('Is not set'),
  is_true: trans('Is true'),
  is_false: trans('Is false'),
  before: trans('Before'),
  after: trans('After'),
  equal_or_before: trans('On or before'),
  equal_or_after: trans('On or after'),
}

const getClauseSymbol = (clause: string) => clauseSymbols[clause] ?? clause
const getClauseLabel = (clause: string) => clauseLabels[clause] ?? clause

// Value helpers for different input types
const textValue = computed({
  get: () => (model.value.value as string) ?? '',
  set: (val: string) => {
    model.value.value = val
  },
})

const numericValue = computed({
  get: () => model.value.value as number | undefined,
  set: (val: number | undefined) => {
    model.value.value = val ?? null
  },
})

const betweenValue = computed({
  get: () => {
    const val = model.value.value
    if (Array.isArray(val) && val.length === 2) return val as [number, number]
    return [undefined, undefined] as [number | undefined, number | undefined]
  },
  set: (val: [number | undefined, number | undefined]) => {
    model.value.value = val as FilterValueScalar
  },
})

// Multi-select is enabled when filter.multiple or clause is 'in'/'not_in'
const isMultiSelect = computed(
  () => props.filter.multiple || model.value.clause === 'in' || model.value.clause === 'not_in',
)

// Set select value - handles both single and multiple selection
const setSelectValue = computed({
  get: () => {
    const val = model.value.value
    if (isMultiSelect.value) {
      // Multi-select: return array of strings
      if (Array.isArray(val)) return val.map(String)
      if (val !== null && val !== undefined) return [String(val)]
      return []
    }
    // Single select: return string or undefined
    return val !== null && val !== undefined ? String(val) : undefined
  },
  set: (val: string | string[] | undefined) => {
    if (isMultiSelect.value) {
      // Multi-select: store as array
      model.value.value = Array.isArray(val) && val.length > 0 ? val : null
    } else {
      // Single select: store as single value
      model.value.value = val ?? null
    }
  },
})

// Date handling
const isDateRange = computed(
  () => props.filter.type === 'date' && (model.value.clause === 'between' || model.value.clause === 'not_between'),
)

const dateValue = computed<DateValue | undefined>(() => {
  if (props.filter.type !== 'date' || !model.value.value || isDateRange.value) return undefined
  try {
    return parseDate(String(model.value.value))
  } catch {
    return undefined
  }
})

const onDateChange = (value: DateValue | undefined) => {
  if (value) {
    model.value.value = formatDateValue(value)
  }
}

const formatDateValue = (d: { year: number; month: number; day: number }) =>
  `${d.year}-${String(d.month).padStart(2, '0')}-${String(d.day).padStart(2, '0')}`

// Date range handling for between/not_between
const dateRangeValue = computed<DateRange>({
  get: () => {
    const val = model.value.value
    if (Array.isArray(val) && val.length === 2) {
      try {
        return {
          start: val[0] ? parseDate(String(val[0])) : undefined,
          end: val[1] ? parseDate(String(val[1])) : undefined,
        } as DateRange
      } catch {
        return { start: undefined, end: undefined } as DateRange
      }
    }
    return { start: undefined, end: undefined } as DateRange
  },
  set: (range: DateRange) => {
    if (range.start && range.end) {
      model.value.value = [formatDateValue(range.start), formatDateValue(range.end)]
    } else if (range.start) {
      model.value.value = [formatDateValue(range.start), null] as unknown as FilterValueScalar
    } else {
      model.value.value = null
    }
  },
})

// Display value
const displayValue = computed(() => {
  if (model.value.value === null || model.value.value === undefined) return ''

  if (props.filter.type === 'set' && props.filter.options) {
    const values = Array.isArray(model.value.value) ? model.value.value : [model.value.value]
    const labels = values.map((v) => props.filter.options?.find((o) => String(o.value) === String(v))?.label ?? v)
    return labels.length > 3 ? `${labels.slice(0, 3).join(', ')}, ...` : labels.join(', ')
  }

  if (props.filter.type === 'date' && Array.isArray(model.value.value)) {
    return model.value.value.filter(Boolean).join(' – ')
  }

  if (Array.isArray(model.value.value)) {
    return model.value.value.join(', ')
  }

  return String(model.value.value)
})
</script>
