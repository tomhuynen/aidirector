<template>
  <aside class="flex w-[22rem] shrink-0 flex-col gap-6 overflow-y-auto border-r border-border p-6">
    <div class="flex items-start justify-between gap-4">
      <h2 class="text-2xl leading-tight font-semibold text-balance">{{ shot.title }}</h2>
      <ConfirmDelete
        :action="shot.links?.storylineReopen ?? '#'"
        :title="$t('Back to the storylines?')"
        :description="
          $t(
            'You choose a storyline for this shot again. Its keyframes, their images and the video are removed. The cast and sets stay in the project.',
          )
        "
        :confirm-label="$t('Back to storylines')"
      >
        <template #trigger>
          <Button type="button" variant="outline" size="sm">
            <Pencil class="size-4" />
            {{ $t('Edit') }}
          </Button>
        </template>
      </ConfirmDelete>
    </div>

    <section v-if="storyline" class="space-y-2">
      <h3 class="flex items-center gap-2 font-semibold">
        <ListOrdered class="size-4 text-muted-foreground" />
        {{ $t('Storyline') }}
      </h3>
      <p class="text-[15px] leading-relaxed text-muted-foreground">{{ storyline.storyline }}</p>
    </section>
    <div v-else class="space-y-3 text-[15px] leading-relaxed">
      <p>{{ shot.subject }}</p>
      <p>{{ shot.action }}</p>
    </div>

    <section class="space-y-2 border-t border-border pt-6">
      <h3 class="flex items-center gap-2 font-semibold">
        <Target class="size-4 text-muted-foreground" />
        {{ $t('Takeaway') }}
      </h3>
      <p class="text-[15px] leading-relaxed">{{ shot.takeaway }}</p>
    </section>
  </aside>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import ConfirmDelete from '@public:components/ConfirmDelete.vue'
import { Button } from '@shared:ui/button'
import { ListOrdered, Pencil, Target } from 'lucide-vue-next'

defineProps<{
  shot: Inertia.Pages.Shots.View['shot']
  storyline: { title: string; storyline: string } | null
}>()
</script>
