<template>
  <Head :title="$t('New shot')" />

  <TopBar :crumbs="crumbs" />

  <div class="flex min-h-0 flex-1">
    <ShotList :shots="shotList" :create-url="project.links?.shotsCreate ?? '#'" />

    <!-- The shot is made right away and opens in the plan chat; this only shows meanwhile. -->
    <main class="flex min-w-0 flex-1 items-center justify-center text-muted-foreground">
      <LoaderCircle class="size-5 animate-spin" />
    </main>
  </div>
</template>
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import EditorLayout from '@public/ts/layouts/Editor.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import { shotCode } from '@public:components/editor/shotCode'
import ShotList from '@public:components/editor/ShotList.vue'
import TopBar from '@public:components/editor/TopBar.vue'
import { index as projectsIndex } from '@routes/public/projects'
import { store } from '@routes/public/shots'
import { LoaderCircle } from 'lucide-vue-next'
import { computed, onMounted } from 'vue'

defineOptions({
  layout: EditorLayout,
})

const props = defineProps<Inertia.Pages.Shots.Create>()

/** The shot is made straight away; its takeaway and plan are worked out in the plan chat. */
onMounted(() => router.post(store.url({ project: props.project.id }), {}, { replace: true }))

const shotList = computed(() =>
  props.siblings.map((sibling) => ({
    id: sibling.id,
    code: shotCode(sibling.position),
    title: sibling.title,
    statusLabel: sibling.statusLabel,
    duration: sibling.duration,
    keyframesCount: sibling.keyframesCount,
    partsCount: sibling.partsCount,
    status: sibling.status,
    busy: sibling.busy,
    thumbnailUrl: sibling.thumbnailUrl,
    videoUrl: sibling.videoUrl,
    voiceOvers: sibling.voiceOvers,
    groupKey: sibling.groupKey,
    waitingFor: sibling.waitingFor,
    url: sibling.url,
  })),
)

const crumbs = computed(() => [
  { title: $t('Projects'), href: projectsIndex.url() },
  { title: props.project.title, href: props.project.links?.view },
  { title: $t('New shot') },
])
</script>
