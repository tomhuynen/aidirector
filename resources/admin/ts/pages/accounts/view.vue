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

              <Table :resource="logins">
                <template #cell(ip)="{ value }">
                  <span>
                    <span v-if="value.bogon" class="text-sm mr-1">🌏</span>
                    <span v-else class="text-sm mr-1" :title="value.countryCode">{{ value.countryFlag }}</span>
                    <code class="text-muted-foreground" :title="value.organization">{{ value.value }}</code>
                  </span>
                </template>

                <template #cell(user_agent)="{ value }">
                  <span class="flex items-center gap-2">
                    <Icon :name="value.deviceTypeIcon" />
                    <span v-if="value.isBot">
                      <div class="truncate" :title="value.value">{{ value.value }}</div>
                    </span>
                    <span v-else :title="value.value">
                      {{ value.clientFamily }} {{ value.clientVersion }} @ {{ value.osName }} {{ value.osVersion }}
                    </span>
                  </span>
                </template>

                <template #cell(created_at)="{ value }">
                  <relative-time :datetime="value">{{ value }}</relative-time>
                </template>
              </Table>
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
              <Table :resource="notifications" />
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
import type { GetResponse } from '@admin/ts/types/utils'
import ConfirmDelete from '@admin:components/ConfirmDelete.vue'
import DateTime from '@admin:components/DateTime.vue'
import Icon from '@admin:components/Icon.vue'
import Page from '@admin:components/Page.vue'
import PageActionItem from '@admin:components/Page/ActionItem.vue'
import { $t } from '@admin:shared/i18n'
import { Deferred, WhenVisible } from '@inertiajs/vue3'
import { Table } from '@inertiaui/table-vue'
import { Badge } from '@shared:ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@shared:ui/card'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@shared:ui/tabs'
import { ref } from 'vue'

defineOptions({
  layout: [AppLayout],
})

defineProps<{
  account: GetResponse<'/admin/accounts/{account}'>['account']
  logins?: GetResponse<'/admin/accounts/{account}'>['logins']
  notifications?: GetResponse<'/admin/accounts/{account}'>['notifications']
}>()

const showDeleteModal = ref(false)
</script>
