<template>
  <div class="space-y-2">
    <label :for="id" class="block text-[15px] font-medium">
      {{ label }}
      <span v-if="hint" class="font-normal text-muted-foreground">({{ hint }})</span>
    </label>
    <textarea
      v-if="rows"
      :id="id"
      v-model="model"
      :rows="rows"
      :maxlength="max"
      :required="required"
      :placeholder="placeholder"
      :class="fieldClass"
    />
    <input
      v-else
      :id="id"
      v-model="model"
      type="text"
      :maxlength="max"
      :required="required"
      :placeholder="placeholder"
      :autofocus="autofocus"
      :class="fieldClass"
    />
    <InputError :message="error" />
  </div>
</template>
<script setup lang="ts">
import InputError from '@public:components/Form/InputError.vue'
import { useId } from 'vue'

defineProps<{
  label: string
  hint?: string
  max: number
  rows?: number | string
  required?: boolean
  autofocus?: boolean
  placeholder?: string
  error?: string
}>()

const model = defineModel<string>({ default: '' })
const id = useId()

const fieldClass =
  'block w-full rounded-lg border border-input bg-background px-4 py-3 text-[15px] leading-relaxed placeholder:text-muted-foreground/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none'
</script>
