<template>
  <div class="grid gap-2">
    <slot :id="id" name="label">
      <Label v-if="label" :for="id">{{ label }}</Label>
    </slot>
    <slot />
    <slot name="help" />
    <InputError v-if="error" :message="error" />
  </div>
</template>
<script setup lang="ts">
import InputError from '@admin/ts/components/Form/InputError.vue'
import { Label } from '@shared:ui/label'

export type BaseInputProps = {
  id: string
  error?: string
  label?: string
}

defineProps<BaseInputProps>()
</script>
