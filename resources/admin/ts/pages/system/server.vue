<template>
  <Page :title="$t('Server')">
    <div class="max-w-2xl flex flex-col gap-4">
      <Health :value="health" />

      <Deferred data="php">
        <template #fallback>
          <Skeleton class="h-10 w-full" />
        </template>

        <Php v-if="php" :value="php" />
      </Deferred>

      <Params :value="configuration" :title="$t('Configuration')">
        <template #label-debug>
          {{ $t('Debug mode') }}
        </template>
        <template #value-debug="{ value }">
          <Badge :variant="value ? 'destructive' : 'success'">
            {{ value ? $t('Enabled') : $t('Disabled') }}
          </Badge>
        </template>
        <template #label-scheme>
          {{ $t('Scheme') }}
        </template>
        <template #value-scheme>
          <Badge :variant="configuration.scheme.match ? 'success' : 'destructive'">
            {{
              configuration.scheme.match
                ? configuration.scheme.configured
                : $t('configured app.url does not match current scheme: :current', {
                    current: configuration.scheme.current,
                  })
            }}
          </Badge>
        </template>
        <template #value-path>
          <Badge v-if="!configuration.path" variant="destructive">
            {{ $t('PATH not set') }}
          </Badge>
          <details v-else>
            <summary>
              <Badge variant="outline">
                {{ $t('PATH set') }}
              </Badge>
            </summary>
            <ol class="py-4 px-10 bg-muted rounded-md mt-2 list-decimal">
              <li v-for="(path, index) in configuration.path" :key="index" class="p-1">
                <code class="text-sm text-muted-foreground">{{ path }}</code>
              </li>
            </ol>
          </details>
        </template>
        <template #value-composerDev>
          <Badge :variant="configuration.composerDev ? 'destructive' : 'success'">
            {{ configuration.composerDev ? $t('Dev packages installed') : $t('No dev packages installed') }}
          </Badge>
        </template>
      </Params>
    </div>
  </Page>
</template>
<script setup lang="ts">
import AppLayout from '@admin/ts/layouts/App.vue'
import type { Inertia } from '@admin/ts/types/utils'
import Page from '@admin:components/Page.vue'
import { $t } from '@admin:shared/i18n'
import { Deferred } from '@inertiajs/vue3'
import { Badge } from '@shared:ui/badge'
import Skeleton from '@shared:ui/skeleton/Skeleton.vue'

import Health from './server/health.vue'
import Params from './server/Params.vue'
import Php from './server/php.vue'

defineOptions({
  layout: [AppLayout],
})

type Props = Inertia.Pages.System.Server

defineProps<{
  configuration: Props['configuration']
  health: Props['health']
  php?: Props['php']
}>()
</script>
