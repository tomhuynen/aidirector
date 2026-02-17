<template>
  <Base :id="id" :label="label" :error="error">
    <template #label>
      <slot :id="id" name="label" />
    </template>
    <Textarea :id="id" v-model="model" v-bind="$attrs" />
    <template #help>
      <slot name="help" />
    </template>
  </Base>
</template>
<script setup lang="ts">
import { Textarea } from '@shared:ui/textarea'
import { useId } from 'vue'

import type { BaseInputProps } from './Base.vue'
import Base from './Base.vue'

const id = useId()

defineProps<Omit<BaseInputProps, 'id'>>()
defineOptions({ inheritAttrs: false })

const model = defineModel<string | number | undefined>()
</script>
