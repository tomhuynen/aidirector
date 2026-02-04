<template>
  <Page :title="$t('Database')">
    <div class="max-w-2xl flex flex-col gap-4">
      <Card class="gap-0 pb-0">
        <CardHeader class="border-b">
          <CardTitle>
            {{ $t('Database') }}
          </CardTitle>
        </CardHeader>
        <CardContent class="p-0">
          <dl class="divide-y">
            <DefinitionListRow :label="$t('Rows')">
              {{ databaseInfo.stats.rows }}
            </DefinitionListRow>
            <DefinitionListRow :label="$t('Size')">
              {{ databaseInfo.stats.size }}
            </DefinitionListRow>
            <template v-for="(item, key) in databaseInfo.variables" :key="key">
              <DefinitionListRow :label="item.title">
                {{ item.value }}
              </DefinitionListRow>
            </template>
          </dl>
        </CardContent>
      </Card>

      <Card class="gap-0 pb-0">
        <CardHeader class="border-b">
          <CardTitle>
            {{ $t('Backups') }}
          </CardTitle>
        </CardHeader>
        <CardContent class="p-0">
          <dl class="divide-y">
            <DefinitionListRow :label="$t('Has encryption')">
              <Badge :variant="backupInfo.hasEncryption ? 'success' : 'destructive'">
                {{ backupInfo.hasEncryption ? $t('Yes') : $t('No') }}
              </Badge>
            </DefinitionListRow>
            <DefinitionListRow :label="$t('Backups')">
              <ul class="flex flex-col gap-2">
                <li v-for="backup in backupInfo.backups" :key="backup.hash">
                  <Button variant="outline" :href="backup.url" as="a">
                    <DownloadIcon class="w-4 h-4" />
                    {{ backup.basename }}
                  </Button>
                </li>
              </ul>
            </DefinitionListRow>
          </dl>
        </CardContent>
      </Card>
    </div>
  </Page>
</template>
<script setup lang="ts">
import AppLayout from '@admin/ts/layouts/App.vue'
import type { GetResponse } from '@admin/ts/types/utils'
import DefinitionListRow from '@admin:components/DefinitionListRow.vue'
import Page from '@admin:components/Page.vue'
import { $t } from '@admin:shared/i18n'
import { Badge } from '@shared:ui/badge'
import { Button } from '@shared:ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@shared:ui/card'
import { DownloadIcon } from 'lucide-vue-next'

defineOptions({
  layout: [AppLayout],
})

defineProps<{
  databaseInfo: GetResponse<'/admin/system/database'>['databaseInfo']
  backupInfo: GetResponse<'/admin/system/database'>['backupInfo']
}>()
</script>
