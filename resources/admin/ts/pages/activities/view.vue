<template>
  <Modal slideover>
    <header class="px-4 sm:px-0">
      <h2>{{ $t('Activity') }}</h2>
    </header>
    <div class="mt-6 border-t border-border">
      <dl class="divide-y divide-border">
        <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
          <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Event') }}</dt>
          <dd class="mt-1 text-sm/6 text-foreground sm:col-span-2 sm:mt-0">
            <Badge variant="outline">{{ activity.event }}</Badge>
          </dd>
        </div>
        <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
          <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Subject') }}</dt>
          <dd class="mt-1 text-sm/6 text-foreground sm:col-span-2 sm:mt-0">
            {{ activity.subject.type }} &mdash; {{ activity.subject.id }}
          </dd>
        </div>
        <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
          <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Causer') }}</dt>
          <dd class="mt-1 text-sm/6 text-foreground sm:col-span-2 sm:mt-0">
            <Badge variant="outline"> {{ activity.causer.name }} &lt;{{ activity.causer.email }}&gt; </Badge>
          </dd>
        </div>
        <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
          <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Happened at') }}</dt>
          <dd class="mt-1 text-sm/6 text-foreground sm:col-span-2 sm:mt-0">
            <DateTime :datetime="activity.createdAt" :time="true" />
          </dd>
        </div>
        <div class="px-4 py-6 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-0">
          <dt class="text-sm/6 font-medium text-muted-foreground">{{ $t('Description') }}</dt>
          <dd class="mt-1 text-sm/6 text-foreground sm:col-span-2 sm:mt-0">
            {{ activity.description }}
          </dd>
        </div>
      </dl>
    </div>
    <CodeDiff
      theme="github-dark"
      :old-code="JSON.stringify(activity.changes?.old, null, 2)"
      :new-code="JSON.stringify(activity.changes?.new, null, 2)"
      output-format="line-by-line"
      language="json"
    />
    <div class="flex justify-between absolute bottom-0 left-0 right-0 px-4 py-3 sm:px-6 border-t border-border">
      <Button v-if="links.previous" :href="links.previous" :as="ModalLink" size="sm" variant="outline">
        <ArrowLeft class="size-4" />
        {{ $t('Previous') }}
      </Button>
      <template v-else> &nbsp; </template>

      <Button v-if="links.next" :href="links.next" :as="ModalLink" size="sm" variant="outline">
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
import { ModalLink } from '@inertiaui/modal-vue'
import Badge from '@shared/components/ui/badge/Badge.vue'
import { Button } from '@shared/components/ui/button'
import { ArrowLeft, ArrowRight } from 'lucide-vue-next'
import { CodeDiff } from 'v-code-diff'

defineProps<{
  activity: GetResponse<'/admin/activities/{activity}'>['activity']
  links: GetResponse<'/admin/activities/{activity}'>['links']
}>()
</script>
