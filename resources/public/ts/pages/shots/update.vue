<template>
  <Head :title="shot.id ? $t('Edit brief') : $t('New shot')" />

  <TopBar :crumbs="crumbs" />

  <div class="flex min-h-0 flex-1">
    <ShotList :shots="shotList" :current-id="shot.id" :create-url="project.links?.shotsCreate ?? '#'" />

    <main class="flex min-w-0 flex-1 flex-col">
      <BriefForm
        :project="project"
        :shot="shot"
        :elements="elements"
        :element-types="elementTypes"
        :cancel-url="shot.links?.view ?? project.links?.view"
      />
    </main>
  </div>
</template>
<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import EditorLayout from '@public/ts/layouts/Editor.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import BriefForm from '@public:components/editor/BriefForm.vue'
import { shotCode } from '@public:components/editor/shotCode'
import ShotList from '@public:components/editor/ShotList.vue'
import TopBar from '@public:components/editor/TopBar.vue'
import { index as projectsIndex } from '@routes/public/projects'
import { computed } from 'vue'

defineOptions({
  layout: EditorLayout,
})

const props = defineProps<Inertia.Pages.Shots.Update>()

const shotList = computed(() =>
  props.siblings.map((sibling) => ({
    id: sibling.id,
    code: shotCode(sibling.position),
    title: sibling.title,
    statusLabel: sibling.statusLabel,
    duration: sibling.duration,
    keyframesCount: sibling.keyframesCount,
    thumbnailUrl: sibling.thumbnailUrl,
    url: sibling.url,
  })),
)

const crumbs = computed(() => [
  { title: $t('Projects'), href: projectsIndex.url() },
  { title: props.project.title, href: props.project.links?.view },
  { title: props.shot.id ? shotCode(props.shot.position) : $t('New shot') },
])
</script>
