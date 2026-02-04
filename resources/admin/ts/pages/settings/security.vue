<template>
  <HeadingSmall :title="$t('Security')" :description="$t('Configure a passkey and manage your sessions.')" />

  <template v-if="!passKeysSupported">
    {{ $t('Passkeys are not supported on this browser.') }}
  </template>
  <template v-else>
    <Card>
      <CardHeader class="border-b">
        <CardTitle class="flex items-center gap-2 justify-between">
          {{ $t('Passkeys') }}
          <AddPasskey />
        </CardTitle>
        <CardDescription>
          {{ $t('Manage your passkeys.') }}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <Table :resource="passkeys" />
      </CardContent>
    </Card>
  </template>

  <Card v-if="sessions">
    <CardHeader>
      <CardTitle class="flex items-center gap-2 justify-between">
        {{ $t('Sessions') }}
      </CardTitle>
      <CardDescription>
        {{ $t('This is a list of your logged in devices, revoke any that you do not recognize.') }}
      </CardDescription>
    </CardHeader>
    <CardContent>
      <Table :resource="sessions">
        <template #cell(ip_address)="{ value }">
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
      </Table>
    </CardContent>
  </Card>
</template>
<script setup lang="ts">
import HeadingSmall from '@admin/ts/components/HeadingSmall.vue'
import AddPasskey from '@admin/ts/components/Settings/AddPasskey.vue'
import Icon from '@admin:components/Icon.vue'
import SettingsLayout from '@admin:layouts/settings/Layout.vue'
import { $t } from '@admin:shared/i18n'
import type { GetResponse } from '@admin:types/utils'
import { Table } from '@inertiaui/table-vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@shared:ui/card'
import { browserSupportsWebAuthn } from '@simplewebauthn/browser'
import { computed } from 'vue'

defineOptions({
  layout: [SettingsLayout],
})

defineProps<{
  passkeys: GetResponse<'/admin/settings/security'>['passkeys']
  sessions: GetResponse<'/admin/settings/security'>['sessions']
}>()

const passKeysSupported = computed(() => browserSupportsWebAuthn())
</script>
