<template>
  <Base :id="id" :label="label" :error="error">
    <template #label>
      <slot :id="id" name="label" />
    </template>
    <Select :id="id" v-model="model">
      <SelectTrigger class="w-full">
        <SelectValue :placeholder="placeholder ?? 'Select an option'" />
      </SelectTrigger>
      <SelectContent>
        <SelectItem v-for="option in options" :key="option.value" :value="option.value">
          {{ option.label }}
        </SelectItem>
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

export type SelectOption = {
  label: string
  value: string
}

type Props = Omit<BaseInputProps, 'id'> & {
  options: SelectOption[]
  placeholder?: string
}

defineProps<Props>()
defineOptions({ inheritAttrs: false })

const model = defineModel<string | null | undefined>()
</script>
