<template>
  <Card class="gap-0 pb-0">
    <CardHeader class="border-b">
      <CardTitle>
        {{ $t('PHP') }}
      </CardTitle>
    </CardHeader>
    <CardContent class="p-0">
      <dl v-if="value" class="divide-y">
        <DefinitionListRow v-if="value.releaseInfo" :label="$t('Version')">
          <details>
            <summary>
              <Badge variant="outline">
                {{ value.version }}
              </Badge>
            </summary>
            <dl class="divide-y">
              <DefinitionListRow :label="$t('Active support:')">
                <Badge :variant="isPast(value.releaseInfo.activeUntil) ? 'destructive' : 'success'">
                  <DateTime :datetime="value.releaseInfo.activeUntil" />
                </Badge>
              </DefinitionListRow>
              <DefinitionListRow :label="$t('Security support:')">
                <Badge :variant="isPast(value.releaseInfo.securityUntil) ? 'destructive' : 'success'">
                  <DateTime :datetime="value.releaseInfo.securityUntil" />
                </Badge>
              </DefinitionListRow>
              <DefinitionListRow :label="$t('Patch available:')">
                <Badge :variant="value.releaseInfo.patchAvailable ? 'destructive' : 'outline'">
                  {{ value.releaseInfo.patchAvailable ? $t('Yes') : $t('No') }}
                </Badge>
              </DefinitionListRow>
            </dl>
          </details>
        </DefinitionListRow>
        <DefinitionListRow v-else :label="$t('Version')">
          <Badge variant="outline">
            {{ value.version }}
          </Badge>
        </DefinitionListRow>
        <DefinitionListRow :label="$t('OPcache')">
          <div class="flex items-center gap-2">
            <Badge :variant="value.opcache.enabled ? 'outline' : 'destructive'">
              {{ value.opcache.enabled ? $t('Enabled') : $t('Disabled') }}
            </Badge>
            <span v-if="value.opcache.usedMemory" class="text-xs text-muted-foreground">
              ({{ $t('using') }} {{ filesize(value.opcache.usedMemory) }})
            </span>
            <Button variant="outline" size="sm" @click="resetOpcache">
              <RefreshCcwIcon class="w-4 h-4" />
              {{ $t('Reset') }}
            </Button>
          </div>
        </DefinitionListRow>
        <DefinitionListRow :label="$t('Max upload size')">
          <Badge variant="outline">
            {{ filesize(value.maxUpload) }}
          </Badge>
        </DefinitionListRow>
        <DefinitionListRow :label="$t('Max execution time')">
          <Badge variant="outline">
            {{ $t(':seconds seconds', { seconds: value.maxExecution.toLocaleString() }) }}
          </Badge>
        </DefinitionListRow>
        <DefinitionListRow :label="$t('Memory limit')">
          <Badge variant="outline">
            {{ filesize(value.memoryLimit) }}
          </Badge>
        </DefinitionListRow>
      </dl>
    </CardContent>
  </Card>
</template>
<script setup lang="ts">
import type { Inertia } from '@admin/ts/types/utils'
import DateTime from '@admin:components/DateTime.vue'
import DefinitionListRow from '@admin:components/DefinitionListRow.vue'
import { http } from '@admin:shared/http'
import { isPast } from '@admin:shared/utils/date'
import { router } from '@inertiajs/vue3'
import { server } from '@routes/admin/system'
import { Badge } from '@shared:ui/badge'
import { Button } from '@shared:ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@shared:ui/card'
import filesize from 'filesize.js'
import { RefreshCcwIcon } from 'lucide-vue-next'

defineProps<{
  value: Inertia.Pages.System.Server['php']
}>()

const resetOpcache = () => {
  http
    .post(server.url(), {
      action: 'resetOpcache',
    })
    .then(() => {
      router.reload()
    })
}
</script>
