<template>
  <div class="flex items-center justify-between">
    <Label :for="id" class="flex items-center gap-3 text-sm font-normal">
      <Checkbox :id="id" v-model="model" />
      <slot :label="label">
        <span v-if="label">{{ label }}</span>
      </slot>
    </Label>
    <InputError v-if="error" :message="error" />
  </div>
</template>
<script setup lang="ts">
import InputError from '@public:components/Form/InputError.vue'
import { Checkbox } from '@shared:ui/checkbox'
import { Label } from '@shared:ui/label'
import { useId } from 'vue'

defineProps<{
  label?: string
  error?: string
}>()

const model = defineModel<boolean>()
const id = useId()
</script>
