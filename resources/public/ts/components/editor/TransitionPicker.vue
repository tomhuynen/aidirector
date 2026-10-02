<template>
  <RadioGroup v-model="model" class="gap-2">
    <label
      v-for="transition in transitions"
      :key="transition.value"
      :class="
        cn(
          'flex cursor-pointer items-center gap-3 rounded-lg border border-border px-3 py-2.5 text-sm transition-colors hover:border-muted-foreground/50',
          model === transition.value && 'border-signal bg-signal-soft/40',
        )
      "
    >
      <RadioGroupItem :value="transition.value" />
      {{ transition.label }}
    </label>
  </RadioGroup>
</template>
<script setup lang="ts">
import { cn } from '@shared/lib/utils'
import { RadioGroup, RadioGroupItem } from '@shared:ui/radio-group'

export type ShotTransitionOption = { value: string; label: string }

defineProps<{
  transitions: ShotTransitionOption[]
}>()

const model = defineModel<string>({ required: true })
</script>
