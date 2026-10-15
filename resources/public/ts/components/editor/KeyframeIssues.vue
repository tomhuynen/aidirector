<template>
  <section
    class="space-y-3 rounded-lg border border-amber-500/50 bg-amber-500/10 p-4 text-sm"
    aria-labelledby="keyframe-issues-heading"
  >
    <p id="keyframe-issues-heading" class="font-medium">{{ $t('The check found something') }}</p>
    <ul class="space-y-2">
      <li v-for="(issue, i) in issues.issues" :key="i" class="flex gap-2 leading-relaxed">
        <TriangleAlert class="mt-0.5 size-4 shrink-0 text-amber-500" />
        {{ issue }}
      </li>
    </ul>
    <div class="flex gap-2 pl-6">
      <Button
        v-if="issues.fixUrl"
        type="button"
        size="sm"
        :disabled="resolving.processing"
        @click="resolve(issues.fixUrl)"
      >
        <Wand2 class="size-4" />
        {{ $t('Fix') }}
      </Button>
      <Button
        type="button"
        size="sm"
        variant="ghost"
        :disabled="resolving.processing"
        @click="resolve(issues.dismissUrl)"
      >
        {{ $t('Dismiss') }}
      </Button>
    </div>
  </section>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { Button } from '@shared:ui/button'
import { TriangleAlert, Wand2 } from 'lucide-vue-next'

import type { PanelIssueGroup } from './KeyframesPanel.vue'

/** What the check found in a keyframe: fixing redraws it told what is wrong, dismissing only takes the issues off the list. */
defineProps<{
  issues: PanelIssueGroup
}>()

const resolving = useForm({})
const resolve = (url: string) => resolving.post(url, { preserveScroll: true })
</script>
