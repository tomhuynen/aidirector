<template>
  <Card class="gap-0 pb-0">
    <CardHeader class="border-b">
      <CardTitle>
        {{ title }}
      </CardTitle>
    </CardHeader>
    <CardContent class="p-0">
      <dl class="divide-y">
        <template v-for="(item, key) in value" :key="key">
          <div class="px-6 py-4 sm:grid sm:grid-cols-5">
            <dt class="text-sm/6 font-medium text-muted-foreground sm:col-span-2">
              <slot :name="`label-${key}`" :label="key">
                {{ useChangeCase(key, 'sentenceCase') }}
              </slot>
            </dt>
            <dd class="mt-1 text-sm/6 sm:mt-0 sm:col-span-3">
              <slot :name="`value-${key}`" :value="item">
                <Badge :variant="item ? badgeVariant : 'destructive'">
                  {{ item ?? $t('Not set') }}
                </Badge>
              </slot>
            </dd>
          </div>
        </template>
      </dl>
    </CardContent>
  </Card>
</template>
<script setup lang="ts">
import type { BadgeVariants } from '@shared:ui/badge'
import { Badge } from '@shared:ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@shared:ui/card'
import { useChangeCase } from '@vueuse/integrations/useChangeCase'

withDefaults(
  defineProps<{
    value: Record<string, string | boolean | object | null | Record<string, any> | number>
    title: string
    badgeVariant?: BadgeVariants['variant']
  }>(),
  {
    badgeVariant: 'success',
  },
)
</script>
