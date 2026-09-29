<template>
  <div class="grid gap-2">
    <slot :id="id" name="label">
      <Label v-if="label" :for="id" class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{
        label
      }}</Label>
    </slot>
    <slot />
    <slot name="help" />
    <InputError v-if="error" :message="error" />
  </div>
</template>
<script setup lang="ts">
import InputError from '@public:components/Form/InputError.vue'
import { Label } from '@shared:ui/label'

export type BaseInputProps = {
  id: string
  error?: string
  label?: string
}

defineProps<BaseInputProps>()
</script>
