<template>
  <div class="divide-y divide-secondary border border-secondary rounded-md mb-4">
    <div class="flex items-center justify-between gap-2 p-2">
      <span class="text-xs font-medium leading-none">{{ $t('Upstream Remote') }}</span>
      <Badge variant="secondary">{{ widget.data.upstreamRemote }}</Badge>
    </div>

    <div class="flex items-center justify-between gap-2 p-2">
      <span class="text-xs font-medium leading-none">{{ $t('Local Branch') }}</span>
      <Badge variant="secondary">{{ widget.data.localBranch }}</Badge>
    </div>
  </div>

  <div class="flex gap-2 mb-4">
    <span
      class="flex h-2 w-2 translate-y-1 rounded-full"
      :class="widget.data.commitsBehind > 0 ? 'bg-red-400' : 'bg-green-400'"
    >
    </span>
    <div class="space-y-1">
      <p class="text-sm font-medium leading-none">
        {{ $t(':commitsBehind Updates', { commitsBehind: widget.data.commitsBehind }) }}
      </p>
      <p class="text-sm text-muted-foreground">
        <template v-if="widget.data.commitsBehind > 0">
          {{ $t('You need to pull the latest changes') }}
        </template>
        <template v-else>
          {{ $t('You are up to date') }}
        </template>
      </p>
    </div>
  </div>
  <ul>
    <li v-for="action in widget.actions" :key="action.name">
      <Button size="sm" :disabled="isProcessingAction" :loading="isProcessingAction" @click="executeAction(action)">
        <Icon v-if="action.icon && !isProcessingAction" :name="action.icon" />
        <ProgressIndeterminate v-if="isProcessingAction" />
        {{ action.name }}
      </Button>
    </li>
  </ul>
</template>
<script setup lang="ts">
import ProgressIndeterminate from '@admin/ts/components/ProgressIndeterminate.vue'
import { useWidget } from '@admin/ts/composables/widget'
import type { Widget } from '@admin/ts/types/utils'
import Icon from '@admin:components/Icon.vue'
import { $t } from '@admin:shared/i18n'
import { Badge } from '@shared:ui/badge'
import { Button } from '@shared:ui/button'

const props = defineProps<{ widget: Widget }>()

const { executeAction, isProcessingAction } = useWidget(props.widget.identifier)
</script>
