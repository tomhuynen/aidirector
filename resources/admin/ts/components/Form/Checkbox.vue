<template>
  <div class="flex items-center justify-between">
    <Label :for="id" :class="cn('flex items-center space-x-3', props.class)">
      <Checkbox :id="id" v-model="model" />
      <slot :label="label">
        <span v-if="label">{{ label }}</span>
      </slot>
    </Label>
    <InputError v-if="error" :message="error" />
  </div>
</template>
<script setup lang="ts">
import InputError from '@admin:components/Form/InputError.vue'
import { cn } from '@shared/lib/utils'
import { Checkbox } from '@shared:ui/checkbox'
import { Label } from '@shared:ui/label'
import { type HTMLAttributes, useId } from 'vue'

import type { BaseInputProps } from './Base.vue'

type Props = Omit<BaseInputProps, 'id'> & {
  class?: HTMLAttributes['class']
}

const props = withDefaults(defineProps<Props>(), {
  class: undefined,
})

const model = defineModel<boolean>()
const id = useId()
</script>
