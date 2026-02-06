<template>
  <Page :title="$t('Activities')" :description="$t('View the activities of your users')">
    <DataTable :resource="activities">
      <template #cell(causer)="{ value }">
        <span class="flex items-center gap-1">
          <LinkIcon class="size-3 text-muted-foreground" />
          {{ value }}
        </span>
      </template>
    </DataTable>
  </Page>
</template>
<script setup lang="ts">
import AppLayout from '@admin/ts/layouts/App.vue'
import type { Inertia, PageProps } from '@admin/ts/types/utils'
import Page from '@admin:components/Page.vue'
import { $t } from '@admin:shared/i18n'
import { DataTable } from '@shared:ui/data-table'
import { LinkIcon } from 'lucide-vue-next'

defineOptions({
  layout: [AppLayout],
})

defineProps<PageProps<Inertia.Pages.Activities.Index>>()
</script>
