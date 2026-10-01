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

    <section class="grid gap-6 lg:grid-cols-3">
      <div class="overflow-hidden rounded-xl border border-border bg-card lg:col-span-2">
        <div class="grid md:grid-cols-2">
          <div class="aspect-square border-b border-border bg-paper-deep md:border-r md:border-b-0">
            <img
              v-if="project.styleReferenceUrl"
              :src="project.styleReferenceUrl"
              :alt="$t('Style reference for :title', { title: project.title })"
              class="size-full object-cover"
            />
            <Placeholder v-else class="size-full rounded-none border-0 bg-paper-deep">
              <span class="rounded-md border border-border bg-background/80 px-3 py-1.5 text-xs text-muted-foreground">
                {{ $t('No style chosen yet') }}
              </span>
            </Placeholder>
          </div>
          <div class="space-y-5 p-6">
            <h2 class="text-xl font-semibold">{{ $t('Style') }}</h2>
            <Field :label="$t('Look')" :value="project.style.look" />
            <Field :label="$t('Medium')" :value="project.style.medium" />
            <Field :label="$t('Mood')" :value="project.style.mood" />
            <Field :label="$t('Palette')" :value="project.style.palette" />
          </div>
        </div>
      </div>

      <div class="space-y-5 rounded-xl border border-border bg-card p-6">
        <h2 class="text-xl font-semibold">{{ $t('Format') }}</h2>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-5 text-sm">
          <div class="space-y-1">
            <dt class="text-muted-foreground">{{ $t('Aspect ratio') }}</dt>
            <dd class="font-medium tabular-nums">{{ project.aspectRatio }}</dd>
          </div>
          <div class="space-y-1">
            <dt class="text-muted-foreground">{{ $t('Shot length') }}</dt>
            <dd class="font-medium tabular-nums">{{ $t(':count s', { count: String(project.defaultDuration) }) }}</dd>
          </div>
          <div class="space-y-1">
            <dt class="text-muted-foreground">{{ $t('Video resolution') }}</dt>
            <dd class="font-medium tabular-nums">{{ project.videoResolution }}</dd>
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

    <section class="space-y-4">
      <div class="flex items-baseline justify-between gap-4">
        <h2 class="text-xl font-semibold">
          {{ $t('Reference images') }}
          <span class="font-normal text-muted-foreground tabular-nums">({{ references.length }})</span>
        </h2>
        <p class="text-sm text-muted-foreground">{{ $t('The real things that must be recognisable in the shots.') }}</p>
      </div>
      <p
        v-if="references.length === 0"
        class="rounded-xl border border-dashed border-border px-6 py-8 text-center text-sm text-muted-foreground"
      >
        {{ $t('No reference images yet.') }}
      </p>
      <ul v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
        <li v-for="reference in references" :key="reference.id" class="space-y-2">
          <a
            :href="reference.url"
            target="_blank"
            rel="noopener noreferrer"
            class="block aspect-[4/3] overflow-hidden rounded-lg border border-border bg-paper-deep transition-colors hover:border-muted-foreground/60"
          >
            <img :src="reference.url" :alt="reference.caption ?? ''" class="size-full object-cover" loading="lazy" />
          </a>
          <p v-if="reference.caption" class="line-clamp-2 text-sm leading-snug text-muted-foreground">
            {{ reference.caption }}
          </p>
        </li>
      </ul>
    </section>

    <section class="space-y-4">
      <div class="flex items-baseline justify-between gap-4">
        <h2 class="text-xl font-semibold">
          {{ $t('Cast & sets') }}
          <span class="font-normal text-muted-foreground tabular-nums">({{ elements.length }})</span>
        </h2>
        <p class="text-sm text-muted-foreground">
          {{ $t('People, places and objects that look the same in every shot.') }}
        </p>
      </div>
      <p
        v-if="elements.length === 0"
        class="rounded-xl border border-dashed border-border px-6 py-8 text-center text-sm text-muted-foreground"
      >
        {{ $t('None yet. They are added when you review the cast and sets of a shot.') }}
      </p>
      <ul v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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

    <section class="space-y-4">
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
import Field from '@public:components/editor/Field.vue'
import Placeholder from '@public:components/editor/Placeholder.vue'
import { shotCode } from '@public:components/editor/shotCode'
import Page from '@public:components/Page.vue'
import { Button } from '@shared:ui/button'
import { ArrowRight, ExternalLink, Pencil, Plus } from 'lucide-vue-next'

defineOptions({
  layout: AppLayout,
})

defineProps<Inertia.Pages.Projects.View>()
</script>
