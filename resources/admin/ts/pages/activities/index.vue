<template>
  <Page :title="$t('Activities')" :description="$t('View the activities of your users')">
    <Table :resource="activities">
      <template #cell(causer)="{ value }">
        <span class="flex items-center gap-1">
          <LinkIcon class="size-3 text-muted-foreground" />
          {{ value }}
        </span>
      </template>
    </Table>
  </Page>
</template>
<script setup lang="ts">
import AppLayout from '@admin/ts/layouts/App.vue'
import type { GetResponse } from '@admin/ts/types/utils'
import Page from '@admin:components/Page.vue'
import { $t } from '@admin:shared/i18n'
import { Table } from '@inertiaui/table-vue'
import { LinkIcon } from 'lucide-vue-next'

defineOptions({
  layout: [AppLayout],
})

defineProps<{ activities: GetResponse<'/admin/activities'>['activities'] }>()
</script>
