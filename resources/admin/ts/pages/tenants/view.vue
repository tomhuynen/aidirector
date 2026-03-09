<template>
  <Page :title="tenant.name">
    <template #actions-append>
      <PageActionItem
        v-if="tenant.can.destroy"
        :title="$t('Delete')"
        icon="Trash"
        variant="destructive"
        :disabled="tenant.isCurrent"
        @click="showDeleteModal = true"
      />
    </template>

    <Card class="max-w-3xl">
      <CardHeader class="border-b">
        <CardTitle>{{ $t('Tenant Details') }}</CardTitle>
        <CardDescription>{{ tenant.domain }}</CardDescription>
      </CardHeader>
      <CardContent>
        <dl class="divide-y divide-border">
          <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
            <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Name') }}</dt>
            <dd class="mt-1 text-sm/6 sm:col-span-2 sm:mt-0">{{ tenant.name }}</dd>
          </div>
          <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
            <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Is active') }}</dt>
            <dd class="mt-1 text-sm/6 sm:col-span-2 sm:mt-0">
              <Badge :variant="tenant.isCurrent ? 'default' : 'outline'">
                {{ tenant.isCurrent ? $t('Active') : $t('Inactive') }}
              </Badge>
            </dd>
          </div>
          <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
            <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Domain') }}</dt>
            <dd class="mt-1 text-sm/6 sm:col-span-2 sm:mt-0">
              {{ tenant.domain }}
            </dd>
          </div>
          <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
            <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Created at') }}</dt>
            <dd class="mt-1 text-sm/6 sm:col-span-2 sm:mt-0">
              <DateTime v-if="tenant.createdAt" :datetime="tenant.createdAt" />
            </dd>
          </div>
        </dl>
      </CardContent>
    </Card>

    <ConfirmDelete
      :action="`/admin/tenants/${tenant.id}`"
      :open="showDeleteModal"
      @update:open="showDeleteModal = $event"
    />
  </Page>
</template>

<script setup lang="ts">
import AppLayout from '@admin/ts/layouts/App.vue'
import type { Inertia } from '@admin/ts/types/utils'
import ConfirmDelete from '@admin:components/ConfirmDelete.vue'
import DateTime from '@admin:components/DateTime.vue'
import Page from '@admin:components/Page.vue'
import PageActionItem from '@admin:components/Page/ActionItem.vue'
import { $t } from '@admin:shared/i18n'
import { Badge } from '@shared:ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@shared:ui/card'
import { ref } from 'vue'

defineOptions({
  layout: [AppLayout],
})

defineProps<Inertia.Pages.Tenants.View>()

const showDeleteModal = ref(false)
</script>
