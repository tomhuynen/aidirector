<template>
  <Modal v-slot="{ navigate }">
    <div class="flex flex-col gap-1.5 p-4">
      <h2 class="text-lg font-semibold">{{ $t('Activity') }}</h2>
    </div>
    <div class="flex-1 overflow-y-auto px-4">
      <dl class="divide-y divide-border">
        <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4">
          <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Event') }}</dt>
          <dd class="mt-1 text-sm/6 text-foreground sm:col-span-2 sm:mt-0">
            <Badge variant="outline">{{ activity.event }}</Badge>
          </dd>
        </div>
        <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4">
          <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Subject') }}</dt>
          <dd class="mt-1 text-sm/6 text-foreground sm:col-span-2 sm:mt-0">
            {{ activity.subject.type }} &mdash; {{ activity.subject.id }}
          </dd>
        </div>
        <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4">
          <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Causer') }}</dt>
          <dd class="mt-1 text-sm/6 text-foreground sm:col-span-2 sm:mt-0">
            <Badge v-if="activity.causer?.name && activity.causer?.email" variant="outline">
              {{ activity.causer.name }} &lt;{{ activity.causer.email }}&gt;
            </Badge>
            <Badge v-else variant="outline">
              <Server class="size-3" />
              {{ $t('System') }}
            </Badge>
          </dd>
        </div>
        <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4">
          <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Happened at') }}</dt>
          <dd class="mt-1 text-sm/6 text-foreground sm:col-span-2 sm:mt-0">
            <DateTime :datetime="activity.createdAt" :time="true" />
          </dd>
        </div>
        <div class="py-4 sm:grid sm:grid-cols-3 sm:gap-4">
          <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Description') }}</dt>
          <dd class="mt-1 text-sm/6 text-foreground sm:col-span-2 sm:mt-0">
            {{ activity.description }}
          </dd>
        </div>
      </dl>
      <CodeDiff
        theme="github-dark"
        :old-code="JSON.stringify(activity.changes?.old, null, 2)"
        :new-code="JSON.stringify(activity.changes?.new, null, 2)"
        output-format="line-by-line"
        language="json"
      />
    </div>
    <div class="mt-auto flex items-center justify-between border-t border-border p-4">
      <Button v-if="links.previous" size="sm" variant="outline" @click="navigate(links.previous!)">
        <ArrowLeft class="size-4" />
        {{ $t('Previous') }}
      </Button>
      <span v-else />

      <Button v-if="links.next" size="sm" variant="outline" @click="navigate(links.next!)">
        {{ $t('Next') }}
        <ArrowRight class="size-4" />
      </Button>
    </div>
  </Modal>
</template>
<script setup lang="ts">
import { $t } from '@admin/ts/shared/i18n'
import type { GetResponse } from '@admin/ts/types/utils'
import DateTime from '@admin:components/DateTime.vue'
import Badge from '@shared/components/ui/badge/Badge.vue'
import { Button } from '@shared/components/ui/button'
import { ArrowLeft, ArrowRight, Server } from 'lucide-vue-next'
import { CodeDiff } from 'v-code-diff'

defineProps<{
  activity: GetResponse<'/admin/activities/{activity}'>['activity']
  links: GetResponse<'/admin/activities/{activity}'>['links']
}>()
</script>
