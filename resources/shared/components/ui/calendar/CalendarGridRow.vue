<template>
  <CalendarGridRow data-slot="calendar-grid-row" :class="cn('flex', props.class)" v-bind="forwardedProps">
    <slot />
  </CalendarGridRow>
</template>

<script lang="ts" setup>
import { cn } from '@shared/lib/utils'
import { reactiveOmit } from '@vueuse/core'
import type { CalendarGridRowProps } from 'reka-ui'
import { CalendarGridRow, useForwardProps } from 'reka-ui'
import type { HTMLAttributes } from 'vue'

const props = defineProps<CalendarGridRowProps & { class?: HTMLAttributes['class'] }>()

const delegatedProps = reactiveOmit(props, 'class')

const forwardedProps = useForwardProps(delegatedProps)
</script>
