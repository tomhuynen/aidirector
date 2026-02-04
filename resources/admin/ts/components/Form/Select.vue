<template>
  <Base :id="id" :label="label" :error="error">
    <template #label>
      <slot :id="id" name="label" />
    </template>
    <Select :id="id" v-model="model">
      <SelectTrigger>
        <SelectValue :placeholder="placeholder ?? 'Select an option'" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem v-for="option in options" :key="getOptionValue(option)" :value="getOptionValue(option)">{{
          getOptionLabel(option)
        }}</SelectItem>
      </SelectContent>
    </Select>
    <template #help>
      <slot name="help" />
    </template>
  </Base>
</template>
<script setup lang="ts">
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@shared:ui/select'
import { useId } from 'vue'

import type { BaseInputProps } from './Base.vue'
import Base from './Base.vue'

const id = useId()

type Option =
  | number
  | string
  | {
      label: string
      value: string
    }

type Props = Omit<BaseInputProps, 'id'> & {
  options: Option[]
  placeholder?: string
}

defineProps<Props>()
defineOptions({ inheritAttrs: false })

const getOptionValue = (option: Option) => {
  if (typeof option === 'object') {
    return option.value
  }
  return option
}

const getOptionLabel = (option: Option) => {
  if (typeof option === 'object') {
    return option.label
  }
  return option
}

const model = defineModel<string | number | undefined>()
</script>
