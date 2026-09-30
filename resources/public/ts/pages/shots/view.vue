<template>
  <Head :title="`${code} · ${project.title}`" />

  <TopBar :crumbs="crumbs" />

  <div class="flex min-h-0 flex-1">
    <ShotList
      :shots="shotList"
      :current-id="shot.id"
      :create-url="project.links?.shotsCreate ?? '#'"
      :reorder-url="project.links?.shotsReorder"
    />

    <main class="flex min-w-0 flex-1">
      <Pending
        v-if="state === 'suggesting'"
        :title="$t('Suggesting storylines')"
        :description="$t('The director is reading your brief and writing three possible storylines.')"
      />
      <Storylines
        v-else-if="state === 'options'"
        :options="shot.storylineOptions ?? []"
        :error="shot.storylineError"
        :edit-url="shot.links?.update ?? '#'"
        :suggest-url="shot.links?.storylineSuggest ?? '#'"
        :choose-url="shot.links?.storylineChoose ?? '#'"
      />
      <Pending
        v-else-if="state === 'planning'"
        :title="$t('Planning keyframes')"
        :description="$t('The director is breaking the chosen storyline into keyframes.')"
      />
      <template v-else-if="state === 'keyframes'">
        <ShotDetails :shot="shot" :storyline="shot.chosenStoryline" />
        <KeyframesPanel
          :keyframes="shot.storyline?.keyframes ?? []"
          :aspect-ratio="aspectRatio"
          :duration="duration"
          :error="shot.storylineError"
          :generate-url="shot.links?.storylineGenerate ?? '#'"
        />
      </template>
      <BriefForm v-else :project="project" :shot="shot" />
    </main>
  </div>
</template>
<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3'
import EditorLayout from '@public/ts/layouts/Editor.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import BriefForm from '@public:components/editor/BriefForm.vue'
import KeyframesPanel from '@public:components/editor/KeyframesPanel.vue'
import Pending from '@public:components/editor/Pending.vue'
import { shotCode } from '@public:components/editor/shotCode'
import ShotDetails from '@public:components/editor/ShotDetails.vue'
import ShotList from '@public:components/editor/ShotList.vue'
import Storylines from '@public:components/editor/Storylines.vue'
import TopBar from '@public:components/editor/TopBar.vue'
import { index as projectsIndex } from '@routes/public/projects'
import { computed, watch } from 'vue'

defineOptions({
  layout: EditorLayout,
})

const props = defineProps<Inertia.Pages.Shots.View>()

const code = computed(() => shotCode(props.shot.position))
const aspectRatio = computed(() => props.shot.aspectRatioOverride ?? props.project.aspectRatio)
const duration = computed(() => props.shot.duration ?? props.project.defaultDuration)

type State = 'brief' | 'suggesting' | 'options' | 'planning' | 'keyframes'

const state = computed<State>(() => {
  switch (props.shot.status) {
    case 'options-pending':
      return 'suggesting'
    case 'options-ready':
      return 'options'
    case 'storyline-pending':
      return 'planning'
    case 'storyline-ready':
    case 'keyframes-ready':
    case 'video-ready':
      return props.shot.storyline ? 'keyframes' : 'options'
    default:
      return 'brief'
  }
})

const { start, stop } = usePoll(3000, { only: ['shot', 'siblings'] }, { autoStart: false })

watch(
  state,
  (current) => {
    if (current === 'suggesting' || current === 'planning') {
      start()
    } else {
      stop()
    }
  },
  { immediate: true },
)

const shotList = computed(() =>
  props.siblings.map((sibling) => ({
    id: sibling.id,
    code: shotCode(sibling.position),
    title: sibling.title,
    statusLabel: sibling.statusLabel,
    duration: sibling.duration,
    keyframesCount: sibling.keyframesCount,
    url: sibling.url,
  })),
)

const crumbs = computed(() => [
  { title: $t('Projects'), href: projectsIndex.url() },
  { title: props.project.title, href: props.project.links?.view },
  { title: code.value },
])
</script>
