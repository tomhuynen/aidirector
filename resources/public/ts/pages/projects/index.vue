<template>
  <Page
    :eyebrow="$t('Projects')"
    :title="$t('Your projects')"
    :description="
      projects.length === 0
        ? $t(
            'Each project holds a purpose, a style and a sequence of shots. Start a new project to bring your ideas to life.',
          )
        : $t('Each project holds a purpose, a style and a sequence of shots.')
    "
  >
    <!-- Without projects the empty state has its own call to create the first one. -->
    <template v-if="projects.length > 0" #actions>
      <Button as-child>
        <Link :href="create.url()">
          <Plus class="size-4" />
          {{ $t('New project') }}
        </Link>
      </Button>
    </template>

    <ProjectsEmpty v-if="projects.length === 0" :create-url="create.url()" />

    <ul v-else class="space-y-4">
      <li
        v-for="project in projects"
        :key="project.id"
        class="overflow-hidden rounded-xl border border-border bg-card transition-colors hover:border-muted-foreground/60"
      >
        <Link :href="project.links?.view ?? '#'" class="group flex min-h-64 flex-col md:flex-row">
          <div class="flex shrink-0 flex-col justify-between gap-8 p-6 md:w-[26rem]">
            <div class="space-y-3">
              <p class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase">
                {{ project.purposeLabel }}
              </p>
              <h2 class="font-display text-2xl leading-tight font-medium text-balance">{{ project.title }}</h2>
              <p v-if="project.description" class="line-clamp-4 text-sm leading-relaxed text-muted-foreground">
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
          </div>

          <div
            class="relative min-h-48 flex-1 overflow-hidden border-t border-border bg-paper-deep md:border-t-0 md:border-l"
          >
            <img
              v-if="project.coverUrl ?? project.styleReferenceUrl"
              :src="(project.coverUrl ?? project.styleReferenceUrl) as string"
              :alt="
                project.coverUrl
                  ? $t('The cast and sets of :title', { title: project.title })
                  : $t('Style reference for :title', { title: project.title })
              "
              class="absolute inset-0 size-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
            />
            <Placeholder v-else class="absolute inset-0 size-full rounded-none border-0 bg-paper-deep">
              <span class="rounded-md border border-border bg-background/80 px-3 py-1.5 text-xs text-muted-foreground">
                {{ $t('No style chosen yet') }}
              </span>
            </Placeholder>
          </div>
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
import Placeholder from '@public:components/editor/Placeholder.vue'
import Page from '@public:components/Page.vue'
import ProjectsEmpty from '@public:components/ProjectsEmpty.vue'
import { create } from '@routes/public/projects'
import { Button } from '@shared:ui/button'
import { Plus } from 'lucide-vue-next'

defineOptions({
  layout: AppLayout,
})

defineProps<Inertia.Pages.Projects.Index>()
</script>
