<template>
  <Page :eyebrow="project.purposeLabel" :title="project.title" :description="project.description ?? undefined">
    <template #actions>
      <Button as-child variant="outline">
        <Link :href="project.links?.update ?? '#'">
          <Pencil class="size-4" />
          {{ $t('Edit project') }}
        </Link>
      </Button>
      <Button as-child>
        <Link :href="project.links?.editor ?? project.links?.shotsCreate ?? '#'">
          {{ shots.length > 0 ? $t('Open editor') : $t('Add the first shot') }}
          <ArrowRight class="size-4" />
        </Link>
      </Button>
    </template>

    <!-- The cast and sets group picture, or the chosen style sheet when the cast was skipped. -->
    <figure v-if="headerImage" class="overflow-hidden rounded-xl border border-border bg-card">
      <img
        :src="headerImage"
        :alt="
          project.coverUrl
            ? $t('The cast and sets of :title', { title: project.title })
            : $t('Style reference for :title', { title: project.title })
        "
        class="aspect-[21/9] w-full object-cover"
      />
    </figure>

    <section>
      <div class="space-y-5 rounded-xl border border-border bg-card p-6">
        <FormatPicker
          :formats="videoFormats"
          :aspect-ratio="project.aspectRatio"
          :resolution="project.videoResolution"
          :has-shots="shots.length > 0"
          :save-url="project.links?.format ?? '#'"
        />
        <dl class="grid grid-cols-2 gap-x-4 gap-y-4 border-t border-border pt-4 text-sm">
          <div class="space-y-1">
            <dt class="text-muted-foreground">{{ $t('Shot length') }}</dt>
            <dd class="font-medium tabular-nums">{{ $t(':count s', { count: String(project.defaultDuration) }) }}</dd>
          </div>
          <div class="space-y-1">
            <dt class="text-muted-foreground">{{ $t('Shots') }}</dt>
            <dd class="font-medium tabular-nums">{{ shots.length }}</dd>
          </div>
          <div v-if="project.website" class="col-span-2 space-y-1">
            <dt class="text-muted-foreground">{{ $t('Website') }}</dt>
            <dd class="truncate font-medium">
              <a
                :href="project.website"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1.5 text-signal hover:underline"
              >
                {{ project.website.replace(/^https?:\/\//, '') }}
                <ExternalLink class="size-3.5" />
              </a>
            </dd>
          </div>
        </dl>
      </div>
    </section>

    <section v-if="elements.length > 0" class="space-y-4">
      <div class="flex items-baseline justify-between gap-4">
        <h2 class="text-xl font-semibold">
          {{ $t('Cast & sets') }}
          <span class="font-normal text-muted-foreground tabular-nums">({{ elements.length }})</span>
        </h2>
        <p class="text-sm text-muted-foreground">
          {{ $t('People, places and objects that look the same in every shot.') }}
        </p>
      </div>
      <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <li
          v-for="element in elements"
          :key="element.id"
          class="flex gap-4 rounded-xl border border-border bg-card p-3"
        >
          <img
            v-if="element.imageUrl"
            :src="element.imageUrl"
            :alt="element.name"
            class="size-24 shrink-0 rounded-lg border border-border bg-paper-deep object-cover"
            loading="lazy"
          />
          <Placeholder v-else class="size-24 shrink-0 bg-background" />
          <div class="min-w-0 space-y-1">
            <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ element.typeLabel }}</p>
            <p class="font-semibold">{{ element.name }}</p>
            <p class="line-clamp-2 text-sm leading-snug text-muted-foreground">{{ element.description }}</p>
            <p v-if="element.shots.length > 0" class="flex flex-wrap gap-x-2 text-xs">
              <Link
                v-for="shot in element.shots"
                :key="shot.url"
                :href="shot.url"
                class="text-signal tabular-nums hover:underline"
                :title="shot.title"
              >
                {{ shotCode(shot.position) }}
              </Link>
            </p>
          </div>
        </li>
      </ul>
    </section>

    <section v-if="shots.length > 0" class="space-y-4">
      <h2 class="text-xl font-semibold">
        {{ $t('Shots') }}
        <span class="font-normal text-muted-foreground tabular-nums">({{ shots.length }})</span>
      </h2>
      <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <li v-for="shot in shots" :key="shot.id">
          <Link
            :href="shot.url"
            class="group flex items-center gap-4 rounded-xl border border-border bg-card p-3 transition-colors hover:border-muted-foreground/60"
          >
            <img
              v-if="shot.thumbnailUrl"
              :src="shot.thumbnailUrl"
              :alt="shot.title"
              class="h-16 w-24 shrink-0 rounded-md border border-border object-cover"
            />
            <Placeholder v-else class="h-16 w-24 shrink-0 bg-background" />
            <div class="min-w-0 space-y-0.5">
              <p class="text-sm font-semibold text-signal tabular-nums">{{ shotCode(shot.position) }}</p>
              <p class="truncate text-[15px] font-medium">{{ shot.title }}</p>
              <p class="truncate text-xs text-muted-foreground">
                <template v-if="shot.keyframesCount > 0">
                  {{ $t(':count keyframes', { count: String(shot.keyframesCount) }) }} ·
                </template>
                {{ shot.statusLabel }}
              </p>
            </div>
          </Link>
        </li>
        <li>
          <Link
            :href="project.links?.shotsCreate ?? '#'"
            class="flex h-full min-h-[5.5rem] items-center justify-center gap-2 rounded-xl border border-dashed border-border text-sm text-muted-foreground transition-colors hover:border-muted-foreground/60 hover:text-foreground"
          >
            <Plus class="size-4" />
            {{ $t('Add shot') }}
          </Link>
        </li>
      </ul>
    </section>
  </Page>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import Placeholder from '@public:components/editor/Placeholder.vue'
import { shotCode } from '@public:components/editor/shotCode'
import FormatPicker from '@public:components/FormatPicker.vue'
import Page from '@public:components/Page.vue'
import { Button } from '@shared:ui/button'
import { ArrowRight, ExternalLink, Pencil, Plus } from 'lucide-vue-next'
import { computed } from 'vue'

defineOptions({
  layout: AppLayout,
})

const props = defineProps<Inertia.Pages.Projects.View>()

const headerImage = computed(() => props.project.coverUrl ?? props.project.styleReferenceUrl)
</script>
