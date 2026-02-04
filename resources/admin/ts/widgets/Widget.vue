<template>
  <Card>
    <CardHeader>
      <CardTitle>{{ widget.title }}</CardTitle>
      <CardDescription v-if="widget.description">{{ widget.description }}</CardDescription>
    </CardHeader>
    <CardContent>
      <widgetComponent :widget="widget" />
    </CardContent>
  </Card>
</template>
<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@shared:ui/card'
import { defineAsyncComponent } from 'vue'

import type { Widget } from '../types/utils'

const props = defineProps<{
  widget: Widget
}>()

const widgetComponent = defineAsyncComponent(() => import(`./Components/${props.widget.component}.vue`))
</script>
