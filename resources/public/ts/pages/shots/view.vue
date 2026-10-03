<template>
  <Head :title="`${code} · ${project.title}`" />

  <TopBar :crumbs="crumbs" />

  <div class="flex min-h-0 flex-1">
    <ShotList
      :shots="shotList"
      :current-id="shot.id"
      :create-url="project.links?.shotsCreate ?? '#'"
      :reorder-url="project.links?.shotsReorder"
      :merge-url="project.links?.shotsMerge"
      :transitions="shotTransitions"
    />

    <main class="flex min-w-0 flex-1 flex-col">
      <p
        v-if="mergedInto"
        class="flex shrink-0 items-center gap-2 border-b border-border bg-signal-soft/40 px-6 py-2.5 text-sm"
      >
        <Combine class="size-4 text-signal" />
        {{
          $t('This shot is part of “:title”. A new video here updates the merged video.', { title: mergedInto.title })
        }}
        <Link :href="mergedInto.url" class="ml-auto font-medium text-signal hover:underline">{{
          $t('Open merged shot')
        }}</Link>
      </p>
      <div class="flex min-h-0 min-w-0 flex-1">
        <MergedShot
          v-if="merge"
          :title="shot.title"
          :takeaway="shot.takeaway"
          :merge="merge"
          :video-url="shot.videoUrl"
          :error="shot.videoError"
          :pending="shot.status === 'video-pending'"
          :aspect-ratio="aspectRatio"
        />
        <Pending
          v-else-if="state === 'suggesting'"
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
        <template v-else-if="state === 'keyframes' || state === 'planning'">
          <ShotDetails :shot="shot" :storyline="shot.chosenStoryline" />
          <KeyframesPanel
            :keyframes="panelKeyframes"
            :aspect-ratio="aspectRatio"
            :duration="duration"
            :generating="shot.status === 'keyframes-pending'"
            :planning="state === 'planning'"
            :review="shot.keyframeReview"
            :error="shot.storylineError"
            :images-url="shot.links?.keyframesGenerate ?? '#'"
            :choosing="{
              active:
                state === 'planning' ||
                shot.status === 'first-keyframe-pending' ||
                shot.status === 'first-keyframe-ready',
              pending: state === 'planning' || shot.status === 'first-keyframe-pending',
              optionCount: shot.firstKeyframeOptions,
              chooseUrl: shot.links?.firstKeyframeChoose ?? '#',
              moreUrl: shot.links?.firstKeyframeMore ?? '#',
              adjustUrl: shot.links?.firstKeyframeAdjust,
              adjusting: shot.status === 'first-keyframe-ready' && Boolean(keyframes[0]?.rendering),
            }"
            :reorder-url="shot.links?.keyframesReorder ?? '#'"
            :new-keyframe="{
              storeUrl: shot.links?.keyframesStore ?? '#',
              max: shot.maxKeyframes,
            }"
            :video="{
              url: shot.videoUrl,
              error: shot.videoError,
              resolution: shot.videoResolution,
              resolutions: shot.videoResolutions,
              pending: shot.status === 'video-pending',
              generateUrl: shot.links?.videoGenerate ?? '#',
            }"
          />
        </template>
        <BriefForm v-else :project="project" :shot="shot" :elements="elements" :element-types="elementTypes" />
      </div>
    </main>
  </div>
</template>
<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3'
import EditorLayout from '@public/ts/layouts/Editor.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import BriefForm from '@public:components/editor/BriefForm.vue'
import KeyframesPanel, { type PanelKeyframe } from '@public:components/editor/KeyframesPanel.vue'
import MergedShot from '@public:components/editor/MergedShot.vue'
import Pending from '@public:components/editor/Pending.vue'
import { shotCode } from '@public:components/editor/shotCode'
import ShotDetails from '@public:components/editor/ShotDetails.vue'
import ShotList from '@public:components/editor/ShotList.vue'
import Storylines from '@public:components/editor/Storylines.vue'
import TopBar from '@public:components/editor/TopBar.vue'
import { index as projectsIndex } from '@routes/public/projects'
import { Combine } from 'lucide-vue-next'
import { computed, watch } from 'vue'

defineOptions({
  layout: EditorLayout,
})

const props = defineProps<Inertia.Pages.Shots.View>()

const code = computed(() => shotCode(props.shot.position))
const aspectRatio = computed(() => props.shot.aspectRatioOverride ?? props.project.aspectRatio)
const duration = computed(() => props.shot.seconds)

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
    case 'first-keyframe-pending':
    case 'first-keyframe-ready':
    case 'keyframes-pending':
    case 'keyframes-ready':
    case 'video-pending':
    case 'video-ready':
      return props.shot.storyline ? 'keyframes' : 'options'
    default:
      return 'brief'
  }
})

/**
 * While images are being generated the rendered keyframes are shown as they
 * arrive; before that the panel shows the plan with empty frames.
 */
const panelKeyframes = computed<PanelKeyframe[]>(() =>
  props.keyframes.length > 0
    ? props.keyframes.map((keyframe) => ({
        id: keyframe.id,
        title: keyframe.title,
        description: keyframe.description,
        imageUrl: keyframe.imageUrl,
        thumbnailUrl: keyframe.thumbnailUrl,
        rendering: keyframe.rendering,
        renderStage: keyframe.renderStage,
        renderNote: keyframe.renderNote,
        renderError: keyframe.renderError,
        renders: keyframe.renders,
        elements: keyframe.elements,
        updateUrl: keyframe.links.update,
        tweakUrl: keyframe.links.tweak,
        chooseRenderUrl: keyframe.links.chooseRender,
        destroyUrl: keyframe.links.destroy,
      }))
    : (props.shot.storyline?.keyframes ?? []).map((keyframe, i) => ({
        id: String(i),
        title: keyframe.title,
        description: keyframe.description,
        imageUrl: null,
        thumbnailUrl: null,
        rendering: props.shot.status === 'keyframes-pending',
        renderError: null,
        renders: [],
        elements: [],
        updateUrl: null,
        tweakUrl: null,
        chooseRenderUrl: null,
        destroyUrl: null,
      })),
)

const busy = computed(
  () =>
    state.value === 'suggesting' ||
    state.value === 'planning' ||
    props.shot.status === 'first-keyframe-pending' ||
    props.shot.status === 'keyframes-pending' ||
    props.shot.status === 'video-pending' ||
    props.keyframes.some((keyframe) => keyframe.rendering),
)

const { start, stop } = usePoll(3000, { only: ['shot', 'keyframes', 'siblings'] }, { autoStart: false })

watch(
  busy,
  (current) => {
    if (current) {
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
    partsCount: sibling.partsCount,
    status: sibling.status,
    thumbnailUrl: sibling.thumbnailUrl,
    url: sibling.url,
  })),
)

const crumbs = computed(() => [
  { title: $t('Projects'), href: projectsIndex.url() },
  { title: props.project.title, href: props.project.links?.view },
  { title: code.value },
])
</script>
