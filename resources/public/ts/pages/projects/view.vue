<template>
  <Page :eyebrow="project.purposeLabel" :title="project.title" :description="project.description ?? undefined">
    <template #actions>
      <Button as-child variant="outline">
        <Link :href="project.links?.update ?? '#'">{{ $t('Edit project') }}</Link>
      </Button>
      <Button as-child>
        <Link :href="project.links?.shotsCreate ?? '#'">{{ $t('Add shot') }}</Link>
      </Button>
    </template>

    <div class="grid gap-12 lg:grid-cols-[1fr_300px]">
      <section class="space-y-6">
        <SectionHeading
          :title="$t('Shots')"
          :description="$t('In order. The director reads neighbouring shots for continuity.')"
        />

        <EmptyState
          v-if="shots.length === 0"
          :title="$t('No shots yet')"
          :description="
            $t('A shot is one idea: who or what we see, what happens, and what the viewer should take away.')
          "
        >
          <Button as-child>
            <Link :href="project.links?.shotsCreate ?? '#'">{{ $t('Add the first shot') }}</Link>
          </Button>
        </EmptyState>

        <ol v-else class="divide-y divide-border rounded-lg border border-border bg-card">
          <li v-for="(shot, i) in shots" :key="shot.id" class="flex items-start gap-5 p-5">
            <span class="font-display w-8 shrink-0 pt-0.5 text-2xl text-muted-foreground tabular-nums">{{
              String(shot.position).padStart(2, '0')
            }}</span>
            <div class="min-w-0 flex-1 space-y-2">
              <div class="flex flex-wrap items-center gap-3">
                <Link :href="shot.links?.view ?? '#'" class="font-medium hover:underline">{{ shot.title }}</Link>
                <ShotStatusBadge :status="shot.status" :label="shot.statusLabel" />
              </div>
              <p class="text-sm leading-relaxed text-muted-foreground">
                <span class="text-foreground">{{ shot.subject }}</span> · {{ shot.action }}
              </p>
              <p class="text-sm text-muted-foreground italic">{{ shot.takeaway }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-1">
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                :disabled="i === 0 || reordering"
                :aria-label="$t('Move up')"
                @click="move(i, -1)"
              >
                <ArrowUp class="size-4" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                :disabled="i === shots.length - 1 || reordering"
                :aria-label="$t('Move down')"
                @click="move(i, 1)"
              >
                <ArrowDown class="size-4" />
              </Button>
              <Button as-child variant="ghost" size="icon-sm" :aria-label="$t('Edit shot')">
                <Link :href="shot.links?.update ?? '#'"><Pencil class="size-4" /></Link>
              </Button>
            </div>
          </li>
        </ol>
      </section>

      <aside class="space-y-8">
        <section class="space-y-4 rounded-lg border border-border bg-card p-6">
          <SectionHeading :title="$t('Style guide')" />
          <dl class="space-y-4 text-sm">
            <StyleRow :label="$t('Look')" :value="project.style.look" />
            <StyleRow :label="$t('Medium')" :value="project.style.medium" />
            <StyleRow :label="$t('Mood')" :value="project.style.mood" />
            <StyleRow :label="$t('Palette')" :value="project.style.palette" />
            <StyleRow :label="$t('Output')" :value="`${project.aspectRatio} · ${project.defaultDuration}s`" />
          </dl>
        </section>

        <ConfirmDelete
          v-if="project.can.destroy"
          :action="project.links?.destroy ?? '#'"
          :title="$t('Delete this project?')"
          :description="$t('All shots in this project are deleted with it. This cannot be undone.')"
        >
          <template #trigger>
            <Button type="button" variant="ghost" class="text-destructive hover:text-destructive">
              <Trash2 class="size-4" />
              {{ $t('Delete project') }}
            </Button>
          </template>
        </ConfirmDelete>
      </aside>
    </div>
  </Page>
</template>
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import ConfirmDelete from '@public:components/ConfirmDelete.vue'
import EmptyState from '@public:components/EmptyState.vue'
import Page from '@public:components/Page.vue'
import SectionHeading from '@public:components/SectionHeading.vue'
import ShotStatusBadge from '@public:components/ShotStatusBadge.vue'
import StyleRow from '@public:components/StyleRow.vue'
import { Button } from '@shared:ui/button'
import { ArrowDown, ArrowUp, Pencil, Trash2 } from 'lucide-vue-next'
import { ref } from 'vue'

defineOptions({
  layout: AppLayout,
})

const props = defineProps<Inertia.Pages.Projects.View>()

const reordering = ref(false)

const move = (index: number, delta: number) => {
  const order = props.shots.map((shot) => shot.id)
  const [moved] = order.splice(index, 1)
  order.splice(index + delta, 0, moved)

  reordering.value = true

  router.post(
    props.project.links?.shotsReorder ?? '#',
    { shots: order },
    {
      preserveScroll: true,
      only: ['shots'],
      onFinish: () => {
        reordering.value = false
      },
    },
  )
}
</script>
