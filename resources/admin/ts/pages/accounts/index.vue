<template>
  <Page :title="$t('Accounts')" :description="$t('Manage your accounts')">
    <Table :resource="accounts" />
  </Page>
</template>

<script setup lang="ts">
import AppLayout from '@admin/ts/layouts/App.vue'
import type { GetResponse } from '@admin/ts/types/utils'
import Page from '@admin:components/Page.vue'
import { $t } from '@admin:shared/i18n'
import { Table } from '@inertiaui/table-vue'

defineOptions({
  layout: [AppLayout],
})

defineProps<{ accounts: GetResponse<'/admin/accounts'>['accounts'] }>()
</script>
