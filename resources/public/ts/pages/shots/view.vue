<template>
  <Head :title="`${code} · ${project.title}`" />

  <TopBar
    :crumbs="crumbs"
    :decisions="project.links?.decisions ? { url: project.links.decisions, count: decisionsCount } : undefined"
  />

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
          :download-url="shot.videoDownloadUrl"
          :error="shot.videoError"
          :pending="shot.status === 'video-pending'"
          :aspect-ratio="aspectRatio"
          :voice-over-tracks="shot.voiceOverTracks"
        />
        <template v-else>
          <ShotDetails v-if="!planEditable && !planning" :shot="shot" :storyline="shot.chosenStoryline" />
          <!-- Planned in conversation; an agreed plan is drawn straight away. -->
          <div v-if="planEditable" class="mx-auto flex min-h-0 w-full max-w-3xl flex-1 flex-col px-5 py-4">
            <PlanChat
              :key="`chat-${shot.id}`"
              :chat-url="shot.links?.planChat"
              :conversation="shot.planChat"
              :kinds="shotKinds"
              :elements="elements"
              :new-elements="shot.newElements"
              :elements-url="shot.links?.planElements"
              :waiting-for="shot.waitingFor"
            />
          </div>
          <PlanSkeleton v-else-if="planning" />
          <KeyframesPanel
            v-else
            :keyframes="panelKeyframes"
            :aspect-ratio="aspectRatio"
            :duration="duration"
            :generating="shot.status === 'keyframes-pending'"
            :issue-groups="shot.issueGroups"
            :reviewing="shot.reviewing"
            :fix-all-url="shot.fixableIssues ? shot.links?.issuesFixAll : null"
            :dismiss-all-url="shot.links?.issuesDismissAll"
            :error="shot.storylineError"
            :images-url="shot.links?.keyframesGenerate ?? '#'"
            :choosing="{
              // A montage draws every still straight away: no place or keyframe 1 to choose first.
              active:
                (shot.kind === 'scene' || shot.kind === 'close-up') &&
                (shot.status === 'first-keyframe-pending' || shot.status === 'first-keyframe-ready'),
              pending: shot.status === 'first-keyframe-pending',
              optionCount: usesPlates ? shot.plateOptionCount : shot.firstKeyframeOptions,
              plates: usesPlates ? shot.plateOptions : null,
              chooseUrl: (usesPlates ? shot.links?.plateChoose : shot.links?.firstKeyframeChoose) ?? '#',
              moreUrl: shot.links?.firstKeyframeMore ?? '#',
              adjustUrl: usesPlates ? shot.links?.plateAdjust : shot.links?.firstKeyframeAdjust,
              adjusting: shot.status === 'first-keyframe-ready' && Boolean(keyframes[0]?.rendering),
              resetUrl: shot.plateChosen ? shot.links?.plateReset : null,
            }"
            :montage="shot.kind === 'montage'"
            :presenter="shot.kind === 'presenter'"
            :reorder-url="shot.links?.keyframesReorder ?? '#'"
            :new-keyframe="{
              storeUrl: shot.links?.keyframesStore ?? '#',
              max: shot.maxKeyframes,
            }"
            :video="{
              url: shot.videoUrl,
              downloadUrl: shot.videoDownloadUrl,
              error: shot.videoError,
              resolution: shot.videoResolution,
              resolutions: shot.videoResolutions,
              pending: shot.status === 'video-pending',
              generateUrl: shot.links?.videoGenerate ?? '#',
              languages: shot.languageVideos,
            }"
          />
        </template>
      </div>
    </main>
  </div>
</template>
<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3'
import EditorLayout from '@public/ts/layouts/Editor.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import KeyframesPanel, { type PanelKeyframe } from '@public:components/editor/KeyframesPanel.vue'
import MergedShot from '@public:components/editor/MergedShot.vue'
import PlanChat from '@public:components/editor/PlanChat.vue'
import PlanSkeleton from '@public:components/editor/PlanSkeleton.vue'
import { shotCode } from '@public:components/editor/shotCode'
import ShotDetails from '@public:components/editor/ShotDetails.vue'
import ShotList from '@public:components/editor/ShotList.vue'
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

/** The draft plan of a shot from the project setup is being written. */
const planning = computed(() => props.shot.status === 'storyline-pending')

/** The shot starts from empty places to choose from, instead of options for keyframe 1. */
const usesPlates = computed(
  () =>
    props.shot.kind === 'scene' &&
    !props.shot.plateChosen &&
    (props.shot.plateOptions.length > 0 || (props.shot.startsWithPlate && !(props.keyframes[0]?.renders.length ?? 0))),
)

/** Nothing drawn yet: the plan is worked out in the plan chat. */
const planEditable = computed(
  () => (props.shot.status === 'storyline-ready' || props.shot.status === 'draft') && props.keyframes.length === 0,
)

const panelKeyframes = computed<PanelKeyframe[]>(() =>
  props.keyframes.length > 0
    ? props.keyframes.map((keyframe) => ({
        id: keyframe.id,
        title: keyframe.title,
        description: keyframe.description,
        spatial: keyframe.spatial ?? '',
        needsDescription: keyframe.needsDescription,
        imageUrl: keyframe.imageUrl,
        thumbnailUrl: keyframe.thumbnailUrl,
        rendering: keyframe.rendering,
        renderStage: keyframe.renderStage,
        renderError: keyframe.renderError,
        renders: keyframe.renders,
        elements: keyframe.elements,
        updateUrl: keyframe.links.update,
        tweakUrl: keyframe.links.tweak,
        chooseRenderUrl: keyframe.links.chooseRender,
        destroyUrl: keyframe.links.destroy,
        copyUrl: keyframe.links.copy,
        moveSelectUrl: keyframe.links.moveSelect,
        moveUrl: keyframe.links.move,
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
    // Waiting for another shot or new cast pictures: drawing starts by itself, so the page keeps looking.
    Boolean(props.shot.waitingFor) ||
    planning.value ||
    props.shot.status === 'first-keyframe-pending' ||
    props.shot.status === 'keyframes-pending' ||
    props.shot.status === 'video-pending' ||
    props.shot.reviewing ||
    props.keyframes.some((keyframe) => keyframe.rendering || keyframe.renderStage === 'checking') ||
    props.shot.voiceOverTracks.some((track) => track.status === 'pending'),
)

const { start, stop } = usePoll(3000, { only: ['shot', 'keyframes', 'siblings'] }, { autoStart: false })

/*
 * Other shots that are still being generated: only the list is refreshed,
 * so their spinners clear when they are done.
 */
const othersBusy = computed(
  () => !busy.value && props.siblings.some((sibling) => sibling.busy && sibling.id !== props.shot.id),
)
const siblingsPoll = usePoll(5000, { only: ['siblings'] }, { autoStart: false })

/** The cast and sets whose pictures were redrawn from the plan chat. */
const redrawn = computed(() => (props.shot.planChat ?? []).flatMap((turn) => turn.adjusted ?? []))

/** Cast and sets added or redrawn from the plan chat whose pictures are still being drawn. */
const newElementsDrawing = computed(() =>
  props.elements.some(
    (element) =>
      element.rendering && (props.shot.addedElements.includes(element.id) || redrawn.value.includes(element.id)),
  ),
)
const elementsPoll = usePoll(4000, { only: ['elements'] }, { autoStart: false })

watch(newElementsDrawing, (current) => (current ? elementsPoll.start() : elementsPoll.stop()), { immediate: true })

watch(othersBusy, (current) => (current ? siblingsPoll.start() : siblingsPoll.stop()), { immediate: true })

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
  { title: code.value },
])
</script>
