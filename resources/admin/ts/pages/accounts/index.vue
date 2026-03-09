<template>
  <Page :title="$t('Accounts')" :description="$t('Manage your accounts')">
    <DataTable :resource="accounts" />
  </Page>
</template>

<script setup lang="ts">
import AppLayout from '@admin/ts/layouts/App.vue'
import type { Inertia } from '@admin/ts/types/utils'
import Page from '@admin:components/Page.vue'
import { $t } from '@admin:shared/i18n'
import { DataTable } from '@shared:ui/data-table'

defineOptions({
  layout: [AppLayout],
})

defineProps<Inertia.Pages.Accounts.Index>()
</script>
