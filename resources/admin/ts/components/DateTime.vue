<template>
  <time :datetime="datetimeString" :title="title">{{ dateDisplay }}</time>
</template>

<script lang="ts" setup>
import { useSetting } from '@admin:composables/settings'
import { computed, type Ref } from 'vue'

const props = withDefaults(
  defineProps<{
    datetime: Date | string
    date?: boolean
    time?: boolean
    weekday?: 'short' | 'long' | 'narrow' | undefined
    year?: 'numeric' | '2-digit' | false
    month?: 'numeric' | '2-digit' | 'long' | 'short' | 'narrow'
    day?: 'numeric' | '2-digit'
    useTitle?: boolean
  }>(),
  {
    date: true,
    time: false,
    weekday: undefined,
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    useTitle: true,
  },
)

const tz: Ref<string | undefined> = useSetting('timezone')
const timezone = computed(() => tz.value ?? Intl.DateTimeFormat().resolvedOptions().timeZone)

const date = computed(() => {
  if (props.datetime === 'now') {
    return new Date()
  }

  if (typeof props.datetime === 'string' && props.datetime.length === 10) {
    // just a date, correct timezone offset
    const [year, month, day] = props.datetime.split('-').map(Number)

    return new Date(Date.UTC(year, month - 1, day))
  }

  return new Date(props.datetime)
})

const datetimeString = computed(() => date.value.toISOString())
const locale = document.documentElement?.lang || 'en-US'

const options: Intl.DateTimeFormatOptions = props.date
  ? {
      weekday: props.weekday,
      year: props.year !== false ? props.year : undefined,
      month: props.month,
      day: props.day,
    }
  : {}

if (props.time) {
  options.hour = '2-digit'
  options.minute = '2-digit'
}

const dateDisplay = computed(() => {
  const formatter = new Intl.DateTimeFormat(locale, {
    ...options,
    timeZone: timezone.value,
  })

  return formatter.format(date.value)
})

const title = computed(() => {
  if (!props.useTitle) {
    return
  }

  if (typeof props.datetime === 'string' && props.datetime.length === 10) {
    return date.value.toLocaleDateString(locale, {
      timeZone: timezone.value,
    })
  }

  return date.value.toLocaleString(locale, {
    timeZone: timezone.value,
  })
})
</script>
