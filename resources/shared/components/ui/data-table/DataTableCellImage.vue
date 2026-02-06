<template>
  <div v-if="value" class="flex min-w-max items-center" :class="positionClass">
    <div v-if="images.length > 0" :class="{ 'flex flex-row -space-x-1': images.length > 1 }">
      <component
        :is="value.icon ? (iconComponent ?? 'span') : 'img'"
        v-for="(imageUrl, index) in images"
        :key="index"
        :class="[
          sizeClass,
          { 'rounded-full': value.rounded, 'ring-2 ring-background': images.length > 1 },
          value.class,
        ]"
        v-bind="
          value.icon
            ? {}
            : {
                src: imageUrl,
                loading: 'lazy',
                alt: value.alt,
                title: value.title,
                width: value.width,
                height: value.height,
              }
        "
      />

      <div
        v-if="value.remaining"
        class="flex items-center justify-center ps-2 text-xs font-medium text-muted-foreground"
      >
        <span>+{{ value.remaining }}</span>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

import { useIcons } from './composables/useIcons'
import type { ColumnImage } from './types'

const props = defineProps<{
  value: ColumnImage | null | undefined
}>()

const { getIconComponent } = useIcons()

const iconComponent = computed(() => (props.value?.icon ? getIconComponent(props.value.icon) : null))

const images = computed(() => {
  if (!props.value) return []
  if (props.value.icon) return [null]

  const urls = Array.isArray(props.value.url) ? props.value.url : [props.value.url]
  return urls.filter(Boolean) as string[]
})

const sizeClass = computed(() => {
  if (!props.value) return 'size-6'

  if (props.value.size === 'custom' && (props.value.width || props.value.height)) {
    return '' // Width/height handled via attributes
  }

  switch (props.value.size) {
    case 'small':
      return 'size-4'
    case 'large':
      return 'size-8'
    case 'extra-large':
      return 'size-10'
    case 'medium':
    default:
      return 'size-6'
  }
})

const positionClass = computed(() => ({
  'flex-row': !props.value?.position || props.value.position === 'start',
  'flex-row-reverse': props.value?.position === 'end',
}))
</script>
