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
        <DataTable :resource="passkeys" />
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
      <DataTable :resource="sessions">
        <template #cell(ip_address)="{ value }">
          <IpAddressCell :value="value as IpAddressValue" />
        </template>

        <template #cell(user_agent)="{ value }">
          <UserAgentCell :value="value as UserAgentValue" />
        </template>
      </DataTable>
    </CardContent>
  </Card>
</template>
<script setup lang="ts">
import HeadingSmall from '@admin/ts/components/HeadingSmall.vue'
import AddPasskey from '@admin/ts/components/Settings/AddPasskey.vue'
import type { IpAddressValue, UserAgentValue } from '@admin:components/cells'
import { IpAddressCell, UserAgentCell } from '@admin:components/cells'
import SettingsLayout from '@admin:layouts/settings/Layout.vue'
import { $t } from '@admin:shared/i18n'
import type { Inertia } from '@admin:types/utils'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@shared:ui/card'
import { DataTable } from '@shared:ui/data-table'
import { browserSupportsWebAuthn } from '@simplewebauthn/browser'
import { computed } from 'vue'

defineOptions({
  layout: [SettingsLayout],
})

defineProps<Inertia.Pages.Settings.Security.View>()

const passKeysSupported = computed(() => browserSupportsWebAuthn())
</script>
