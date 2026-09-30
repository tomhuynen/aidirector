<template>
  <Head :title="project.title" />

  <TopBar :crumbs="crumbs" />

  <div class="flex min-h-0 flex-1">
    <ShotList :shots="[]" :create-url="project.links?.shotsCreate ?? '#'" />

    <main class="flex min-w-0 flex-1 flex-col items-center justify-center p-8">
      <div class="max-w-md space-y-5 text-center">
        <p class="text-xs font-medium tracking-[0.18em] text-muted-foreground uppercase">{{ project.purposeLabel }}</p>
        <h1 class="font-display text-4xl font-medium text-balance">{{ project.title }}</h1>
        <p v-if="project.description" class="text-sm leading-relaxed text-muted-foreground">
          {{ project.description }}
        </p>
        <p class="text-sm leading-relaxed text-muted-foreground">
          {{ $t('A shot is one idea: who or what we see, what happens, and what the viewer should take away.') }}
        </p>
        <Button as-child size="lg">
          <Link :href="project.links?.shotsCreate ?? '#'">
            <Plus class="size-4" />
            {{ $t('Add the first shot') }}
          </Link>
        </Button>
      </div>
    </main>

    <aside class="flex w-[22rem] shrink-0 flex-col border-l border-border bg-background">
      <div class="flex h-14 shrink-0 items-center border-b border-border px-5">
        <h2 class="text-[15px] font-semibold">{{ $t('Style guide') }}</h2>
      </div>
      <div class="flex-1 space-y-6 overflow-y-auto px-5 py-5">
        <Field :label="$t('Look')" :value="project.style.look" />
        <Field :label="$t('Medium')" :value="project.style.medium" />
        <Field :label="$t('Mood')" :value="project.style.mood" />
        <Field :label="$t('Palette')" :value="project.style.palette" />
        <div class="grid grid-cols-2 gap-4">
          <Field :label="$t('Aspect ratio')" :value="project.aspectRatio" />
          <Field :label="$t('Shot length')" :value="`${project.defaultDuration} s`" />
        </div>
      </div>
      <div class="flex shrink-0 items-center border-t border-border px-5 py-4">
        <Button as-child variant="secondary">
          <Link :href="project.links?.update ?? '#'">{{ $t('Edit project') }}</Link>
        </Button>
      </div>
    </aside>
  </div>
</template>
<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import EditorLayout from '@public/ts/layouts/Editor.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import Field from '@public:components/editor/Field.vue'
import ShotList from '@public:components/editor/ShotList.vue'
import TopBar from '@public:components/editor/TopBar.vue'
import { index as projectsIndex } from '@routes/public/projects'
import { Button } from '@shared:ui/button'
import { Plus } from 'lucide-vue-next'
import { computed } from 'vue'

defineOptions({
  layout: EditorLayout,
})

const props = defineProps<Inertia.Pages.Projects.View>()

const crumbs = computed(() => [{ title: $t('Projects'), href: projectsIndex.url() }, { title: props.project.title }])
</script>
