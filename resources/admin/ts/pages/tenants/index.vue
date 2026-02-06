<template>
  <Page :title="$t('Tenants')" :description="$t('Manage your tenants')">
    <DataTable :resource="tenants" />
  </Page>
</template>

<script setup lang="ts">
import AppLayout from '@admin/ts/layouts/App.vue'
import type { Inertia, PageProps } from '@admin/ts/types/utils'
import Page from '@admin:components/Page.vue'
import { $t } from '@admin:shared/i18n'
import { DataTable } from '@shared:ui/data-table'

defineOptions({
  layout: [AppLayout],
})

defineProps<PageProps<Inertia.Pages.Tenants.Index>>()
</script>
