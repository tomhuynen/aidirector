<template>
  <Page :title="$t('Dashboard')">
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
      <template v-for="(widget, index) in widgets" :key="index">
        <Widget v-if="widget" :widget="widget" />
      </template>
    </div>
  </Page>
</template>
<script setup lang="ts">
import AppLayout from '@admin/ts/layouts/App.vue'
import type { GetResponse } from '@admin/ts/types/utils'
import Widget from '@admin/ts/widgets/Widget.vue'
import Page from '@admin:components/Page.vue'
import { $t } from '@admin:shared/i18n'

defineOptions({
  layout: [AppLayout],
})

defineProps<{
  widgets: GetResponse<'/admin'>['widgets']
}>()
</script>
