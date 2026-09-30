<template>
  <aside class="flex w-[22rem] shrink-0 flex-col gap-6 overflow-y-auto border-r border-border p-6">
    <div class="flex items-center justify-between">
      <h2 class="text-xl font-semibold">{{ $t('Shot details') }}</h2>
      <Button as-child variant="outline" size="sm">
        <Link :href="shot.links?.update ?? '#'">
          <Pencil class="size-4" />
          {{ $t('Edit') }}
        </Link>
      </Button>
    </div>

    <Field :label="$t('Title')" :value="shot.title" />
    <Field :label="$t('Subject')" :value="shot.subject" />
    <Field :label="$t('Action')" :value="shot.action" />
    <Field :label="$t('Takeaway')" :value="shot.takeaway" />

    <div class="space-y-3 pt-2">
      <div class="flex items-center justify-between">
        <h3 class="text-lg font-semibold">{{ $t('Selected storyline') }}</h3>
        <Button type="button" variant="outline" size="sm" :disabled="reopen.processing" @click="change">
          <RefreshCw class="size-4" />
          {{ $t('Change') }}
        </Button>
      </div>
      <div v-if="storyline" class="flex gap-4 rounded-xl border border-border bg-card px-5 py-4">
        <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-signal-soft text-signal">
          <ListOrdered class="size-4" />
        </span>
        <div class="min-w-0 space-y-1">
          <p class="font-semibold">{{ storyline.title }}</p>
          <p class="text-[15px] leading-relaxed text-muted-foreground">{{ storyline.storyline }}</p>
        </div>
      </div>
    </div>

    <div class="mt-auto pt-4">
      <ConfirmDelete
        :action="shot.links?.destroy ?? '#'"
        :title="$t('Delete this shot?')"
        :description="$t('The remaining shots close the gap in the sequence.')"
      >
        <template #trigger>
          <Button type="button" variant="ghost" class="text-destructive hover:text-destructive">
            {{ $t('Delete shot') }}
          </Button>
        </template>
      </ConfirmDelete>
    </div>
  </aside>
</template>
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import ConfirmDelete from '@public:components/ConfirmDelete.vue'
import { Button } from '@shared:ui/button'
import { ListOrdered, Pencil, RefreshCw } from 'lucide-vue-next'

import Field from './Field.vue'

const props = defineProps<{
  shot: Inertia.Pages.Shots.View['shot']
  storyline: { title: string; storyline: string } | null
}>()

const reopen = useForm({})

const change = () => reopen.delete(props.shot.links?.storylineReopen ?? '#')
</script>
