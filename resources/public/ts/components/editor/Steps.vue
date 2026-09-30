<template>
  <ol class="flex w-full" :aria-label="$t('Progress')">
    <li
      v-for="(step, index) in steps"
      :key="step.key"
      class="relative flex flex-1 flex-col items-center gap-3"
      :aria-current="index + 1 === current ? 'step' : undefined"
    >
      <span
        v-if="index < steps.length - 1"
        aria-hidden="true"
        class="absolute top-6 left-1/2 h-0.5 w-full rounded-full"
        :class="connectorClass(index + 1)"
      />

      <component
        :is="isLink(step, index + 1) ? Link : 'span'"
        :href="step.href"
        class="relative z-10 flex size-12 items-center justify-center rounded-full border-2 text-lg font-semibold transition-colors"
        :class="circleClass(index + 1)"
      >
        <Check v-if="index + 1 < current" class="size-5" />
        <span v-else>{{ index + 1 }}</span>
      </component>

      <span class="text-[15px]" :class="index + 1 === current ? 'font-semibold' : 'text-muted-foreground'">
        {{ step.title }}
      </span>
    </li>
  </ol>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { Check } from 'lucide-vue-next'

export type Step = { key: string; title: string; href?: string }

const props = defineProps<{
  steps: Step[]
  current: number
}>()

const isLink = (step: Step, number: number) => Boolean(step.href) && number !== props.current

const circleClass = (number: number) => {
  if (number < props.current) {
    return 'border-signal bg-background text-signal hover:bg-signal-soft/60'
  }

  if (number === props.current) {
    return 'border-signal bg-signal text-primary-foreground shadow-[0_0_0_6px] shadow-signal/20'
  }

  return 'border-muted-foreground/40 bg-background text-muted-foreground'
}

const connectorClass = (number: number) => {
  if (number < props.current) {
    return 'bg-signal'
  }

  if (number === props.current) {
    return 'bg-gradient-to-r from-signal to-border'
  }

  return 'bg-border'
}
</script>
