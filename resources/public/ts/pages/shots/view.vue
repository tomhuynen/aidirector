<template>
  <Page :eyebrow="`${project.title} · ${$t('Shot :n', { n: String(shot.position) })}`" :title="shot.title">
    <template #actions>
      <ShotStatusBadge :status="shot.status" :label="shot.statusLabel" />
      <Button as-child variant="outline">
        <Link :href="shot.links?.update ?? '#'">{{ $t('Edit intent') }}</Link>
      </Button>
    </template>

    <nav class="flex items-center justify-between text-sm">
      <Link
        v-if="previous"
        :href="previous.url"
        class="flex items-center gap-2 text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft class="size-4" />
        {{ $t('Shot :n', { n: String(previous.position) }) }}
      </Link>
      <span v-else />
      <Link :href="project.links?.view ?? '#'" class="text-muted-foreground hover:text-foreground">{{
        $t('All shots')
      }}</Link>
      <Link v-if="next" :href="next.url" class="flex items-center gap-2 text-muted-foreground hover:text-foreground">
        {{ $t('Shot :n', { n: String(next.position) }) }}
        <ArrowRight class="size-4" />
      </Link>
      <span v-else />
    </nav>

    <div class="grid gap-12 lg:grid-cols-[300px_1fr]">
      <aside class="space-y-6">
        <section class="space-y-4 rounded-lg border border-border bg-card p-6">
          <SectionHeading :title="$t('Intent')" />
          <dl class="space-y-4 text-sm">
            <StyleRow :label="$t('Subject')" :value="shot.subject" />
            <StyleRow :label="$t('Action')" :value="shot.action" />
            <StyleRow :label="$t('Takeaway')" :value="shot.takeaway" />
            <StyleRow v-if="shot.notes" :label="$t('Notes')" :value="shot.notes" />
            <StyleRow
              :label="$t('Output')"
              :value="`${shot.aspectRatioOverride ?? project.aspectRatio} · ${shot.duration ?? project.defaultDuration}s`"
            />
          </dl>
        </section>

        <ConfirmDelete
          :action="shot.links?.destroy ?? '#'"
          :title="$t('Delete this shot?')"
          :description="$t('The remaining shots close the gap in the sequence.')"
        >
          <template #trigger>
            <Button type="button" variant="ghost" class="text-destructive hover:text-destructive">
              <Trash2 class="size-4" />
              {{ $t('Delete shot') }}
            </Button>
          </template>
        </ConfirmDelete>
      </aside>

      <section class="space-y-6">
        <SectionHeading :title="$t('Pipeline')" :description="$t('Each stage builds on the one before it.')" />
        <ol class="grid gap-px overflow-hidden rounded-lg border border-border bg-border">
          <li v-for="(stage, i) in stages" :key="stage.title" class="flex items-start gap-5 bg-card p-6">
            <span class="font-display pt-0.5 text-2xl text-muted-foreground tabular-nums">{{ i + 1 }}</span>
            <div class="flex-1 space-y-2">
              <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="font-medium">{{ stage.title }}</h3>
                <Badge variant="outline" class="font-normal text-muted-foreground">{{ $t('Coming next') }}</Badge>
              </div>
              <p class="text-sm leading-relaxed text-muted-foreground">{{ stage.description }}</p>
            </div>
          </li>
        </ol>
      </section>
    </div>
  </Page>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import ConfirmDelete from '@public:components/ConfirmDelete.vue'
import Page from '@public:components/Page.vue'
import SectionHeading from '@public:components/SectionHeading.vue'
import ShotStatusBadge from '@public:components/ShotStatusBadge.vue'
import StyleRow from '@public:components/StyleRow.vue'
import { Badge } from '@shared:ui/badge'
import { Button } from '@shared:ui/button'
import { ArrowLeft, ArrowRight, Trash2 } from 'lucide-vue-next'
import { computed } from 'vue'

defineOptions({
  layout: AppLayout,
})

const props = defineProps<Inertia.Pages.Shots.View>()

const currentIndex = computed(() => props.siblings.findIndex((sibling) => sibling.id === props.shot.id))
const previous = computed(() => props.siblings[currentIndex.value - 1])
const next = computed(() => props.siblings[currentIndex.value + 1])

const stages = computed(() => [
  {
    title: $t('Camera options'),
    description: $t(
      'The director proposes a recommended way to film this shot plus alternatives, judged by the project purpose.',
    ),
  },
  {
    title: $t('Keyframes'),
    description: $t('The chosen option is broken into keyframes. Each keyframe becomes an image you can iterate on.'),
  },
  {
    title: $t('Video'),
    description: $t(
      'The keyframes are numbered into a storyboard and handed to the video model with a locked-down prompt.',
    ),
  },
])
</script>
