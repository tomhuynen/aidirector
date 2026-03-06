<template>
  <Page :title="account.name">
    <template #actions-append>
      <PageActionItem
        v-if="account.can.destroy"
        :title="$t('Delete')"
        icon="Trash"
        variant="destructive"
        :disabled="account.isMe"
        @click="showDeleteModal = true"
      />
    </template>

    <Card class="max-w-3xl">
      <CardHeader class="border-b">
        <CardTitle>{{ $t('Account Details') }}</CardTitle>
        <CardDescription>{{ account.name }}</CardDescription>
      </CardHeader>
      <CardContent>
        <div class="max-w-3xl">
          <dl class="divide-y divide-border">
            <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
              <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Name') }}</dt>
              <dd class="mt-1 text-sm/6 sm:col-span-2 sm:mt-0">{{ account.name }}</dd>
            </div>
            <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
              <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Email') }}</dt>
              <dd class="mt-1 text-sm/6 sm:col-span-2 sm:mt-0">
                {{ account.email }}
              </dd>
            </div>
            <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
              <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Role') }}</dt>
              <dd class="mt-1 text-sm/6 sm:col-span-2 sm:mt-0">
                <div class="flex flex-wrap gap-2">
                  <Badge v-for="role in account.roles" :key="role.name" variant="secondary">{{ role.name }}</Badge>
                </div>
              </dd>
            </div>
            <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
              <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Is me') }}</dt>
              <dd class="mt-1 text-sm/6 sm:col-span-2 sm:mt-0">
                <Badge :variant="account.isMe ? 'default' : 'outline'">{{ account.isMe ? $t('Yes') : $t('No') }}</Badge>
              </dd>
            </div>
            <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
              <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Created at') }}</dt>
              <dd class="mt-1 text-sm/6 sm:col-span-2 sm:mt-0">
                <DateTime v-if="account.createdAt" :datetime="account.createdAt" />
              </dd>
            </div>
          </dl>
        </div>
      </CardContent>
    </Card>

    <Tabs default-value="logins" class="mt-6 max-w-3xl">
      <TabsList class="grid w-full grid-cols-2">
        <TabsTrigger value="logins">{{ $t('Logins') }}</TabsTrigger>
        <TabsTrigger value="notifications">{{ $t('Notifications') }}</TabsTrigger>
      </TabsList>
      <TabsContent value="logins">
        <Card>
          <CardHeader class="border-b">
            <CardTitle>{{ $t('Logins') }}</CardTitle>
            <CardDescription>{{ $t('Your logins') }}</CardDescription>
          </CardHeader>
          <CardContent>
            <Deferred data="logins">
              <template #fallback>
                <TableSkeleton />
              </template>

              <DataTable :resource="logins as TableResource">
                <template #cell(ip)="{ value }">
                  <IpAddressCell :value="value as IpAddressValue" />
                </template>

                <template #cell(user_agent)="{ value }">
                  <UserAgentCell :value="value as UserAgentValue" />
                </template>

                <template #cell(created_at)="{ value }">
                  <RelativeTimeCell :value="value as string" />
                </template>
              </DataTable>
            </Deferred>
          </CardContent>
        </Card>
      </TabsContent>
      <TabsContent value="notifications">
        <Card>
          <CardHeader class="border-b">
            <CardTitle>{{ $t('Notifications') }}</CardTitle>
            <CardDescription>{{ $t('Your notifications') }}</CardDescription>
          </CardHeader>
          <CardContent>
            <WhenVisible data="notifications">
              <template #fallback>
                <TableSkeleton />
              </template>
              <DataTable :resource="notifications" />
            </WhenVisible>
          </CardContent>
        </Card>
      </TabsContent>
    </Tabs>

    <ConfirmDelete
      :action="`/admin/accounts/${account.id}`"
      :open="showDeleteModal"
      @update:open="showDeleteModal = $event"
    />
  </Page>
</template>
<script setup lang="ts">
import TableSkeleton from '@admin/ts/components/TableSkeleton.vue'
import AppLayout from '@admin/ts/layouts/App.vue'
import type { Inertia, PageProps } from '@admin/ts/types/utils'
import type { IpAddressValue, UserAgentValue } from '@admin:components/cells'
import { IpAddressCell, RelativeTimeCell, UserAgentCell } from '@admin:components/cells'
import ConfirmDelete from '@admin:components/ConfirmDelete.vue'
import DateTime from '@admin:components/DateTime.vue'
import Page from '@admin:components/Page.vue'
import PageActionItem from '@admin:components/Page/ActionItem.vue'
import { $t } from '@admin:shared/i18n'
import { Deferred, WhenVisible } from '@inertiajs/vue3'
import { Badge } from '@shared:ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@shared:ui/card'
import { DataTable, type TableResource } from '@shared:ui/data-table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@shared:ui/tabs'
import { ref } from 'vue'

defineOptions({
  layout: [AppLayout],
})

defineProps<PageProps<Inertia.Pages.Accounts.View>>()

const showDeleteModal = ref(false)
</script>
