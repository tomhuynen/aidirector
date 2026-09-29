<template>
  <Page
    :eyebrow="$t('Projects')"
    :title="$t('Your projects')"
    :description="$t('Each project holds a purpose, a style and a sequence of shots.')"
  >
    <template #actions>
      <Button as-child>
        <Link :href="create.url()">{{ $t('New project') }}</Link>
      </Button>
    </template>

    <EmptyState
      v-if="projects.length === 0"
      :title="$t('Nothing here yet')"
      :description="$t('Start with a project. Pick what it is for, describe the look, then add the shots you need.')"
    >
      <Button as-child>
        <Link :href="create.url()">{{ $t('Create your first project') }}</Link>
      </Button>
    </EmptyState>

    <ul
      v-else
      class="grid gap-px overflow-hidden rounded-lg border border-border bg-border sm:grid-cols-2 lg:grid-cols-3"
    >
      <li v-for="project in projects" :key="project.id" class="bg-card">
        <Link
          :href="project.links?.view ?? '#'"
          class="group flex h-full flex-col justify-between gap-8 p-6 transition-colors hover:bg-paper-deep/70"
        >
          <div class="space-y-3">
            <p class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase">
              {{ project.purposeLabel }}
            </p>
            <h2 class="font-display text-2xl leading-tight font-medium text-balance">{{ project.title }}</h2>
            <p v-if="project.description" class="line-clamp-3 text-sm leading-relaxed text-muted-foreground">
              {{ project.description }}
            </p>
          </div>
          <dl class="flex items-center gap-6 text-xs text-muted-foreground">
            <div class="flex items-center gap-1.5">
              <dt class="sr-only">{{ $t('Shots') }}</dt>
              <dd>{{ $t(':count shots', { count: String(project.shotsCount ?? 0) }) }}</dd>
            </div>
            <div class="flex items-center gap-1.5">
              <dt class="sr-only">{{ $t('Aspect ratio') }}</dt>
              <dd>{{ project.aspectRatio }}</dd>
            </div>
          </dl>
        </Link>
      </li>
    </ul>
  </Page>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import EmptyState from '@public:components/EmptyState.vue'
import Page from '@public:components/Page.vue'
import { create } from '@routes/public/projects'
import { Button } from '@shared:ui/button'

defineOptions({
  layout: AppLayout,
})

defineProps<Inertia.Pages.Projects.Index>()
</script>
