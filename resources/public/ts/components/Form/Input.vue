<template>
  <Base :id="id" :label="label" :error="error">
    <template #label>
      <slot :id="id" name="label" />
    </template>
    <InputGroup>
      <InputGroupAddon v-if="$slots.prefix" class="border-r-1 border-border px-3 text-muted-foreground">
        <slot name="prefix" />
      </InputGroupAddon>
      <InputGroupInput :id="id" v-model="model" v-bind="$attrs" />
      <InputGroupAddon
        v-if="$slots.suffix"
        align="inline-end"
        class="border-l-1 border-border px-3 text-muted-foreground"
      >
        <slot name="suffix" />
      </InputGroupAddon>
    </InputGroup>
    <template #help>
      <slot name="help" />
    </template>
  </Base>
</template>
<script setup lang="ts">
import { InputGroup, InputGroupAddon, InputGroupInput } from '@shared:ui/input-group'
import { useId } from 'vue'

import type { BaseInputProps } from './Base.vue'
import Base from './Base.vue'

const id = useId()

defineProps<Omit<BaseInputProps, 'id'>>()
defineOptions({ inheritAttrs: false })

const model = defineModel<string | number | null | undefined>()
</script>
