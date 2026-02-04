<template>
  <Card class="gap-0 pb-0">
    <CardHeader class="border-b">
      <CardTitle>
        {{ $t('Health') }}
      </CardTitle>
    </CardHeader>
    <CardContent class="p-0">
      <dl v-if="value" class="divide-y">
        <DefinitionListRow :label="$t('Cron')">
          <relative-time v-if="value.cron" :datetime="value.cron" />
          <Badge v-else variant="outline">
            {{ $t('Never') }}
          </Badge>
        </DefinitionListRow>
        <DefinitionListRow :label="$t('Healthcheck token')">
          <Badge :variant="value.healthCheckToken ? 'outline' : 'destructive'">
            {{ value.healthCheckToken ?? $t('Not set') }}
          </Badge>
        </DefinitionListRow>
      </dl>
    </CardContent>
  </Card>
</template>
<script setup lang="ts">
import type { Inertia, PageProps } from '@admin/ts/types/utils'
import DefinitionListRow from '@admin:components/DefinitionListRow.vue'
import { Badge } from '@shared:ui/badge'
import { Card, CardContent, CardHeader, CardTitle } from '@shared:ui/card'

defineProps<{
  value: PageProps<Inertia.Pages.System.Server>['health']
}>()
</script>
