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
      :destroy-url="project.links?.shotsDestroy"
      :transitions="shotTransitions"
    />

    <main class="flex min-w-0 flex-1 flex-col">
      <div class="flex min-h-0 min-w-0 flex-1">
        <MergedShot
          v-if="merge"
          :title="shot.title"
          :merge="merge"
          :video-url="shot.videoUrl"
          :download-url="shot.videoDownloadUrl"
          :error="shot.videoError"
          :pending="shot.status === 'video-pending'"
          :aspect-ratio="aspectRatio"
          :voice-over-tracks="shot.voiceOverTracks"
        />
        <template v-else>
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
              :voice-over-url="shot.links?.planVoiceOver"
              :sequence-url="shot.links?.planSequence"
            />
          </div>
          <PlanSkeleton v-else-if="planning" />
          <KeyframesPanel
            v-else
            :chat="{
              url: shot.links?.planChat,
              conversation: shot.planChat,
              kinds: shotKinds,
              elements,
              newElements: shot.newElements,
              elementsUrl: shot.links?.planElements,
              changeUrl: shot.links?.planChange,
              voiceOverUrl: shot.links?.planVoiceOver,
              sequenceUrl: shot.links?.planSequence,
            }"
            :keyframes="panelKeyframes"
            :kind="shot.kind"
            :aspect-ratio="aspectRatio"
            :duration="duration"
            :keyframe-seconds="shot.keyframeSeconds"
            :generating="shot.status === 'keyframes-pending'"
            :issue-groups="shot.issueGroups"
            :reviewing="shot.reviewing"
            :fix-all-url="shot.fixableIssues ? shot.links?.issuesFixAll : null"
            :dismiss-all-url="shot.links?.issuesDismissAll"
            :error="shot.storylineError"
            :retry-url="shot.links?.retry ?? '#'"
            :choosing="{
              active:
                shot.choosesStartFrame &&
                (shot.status === 'first-keyframe-pending' || shot.status === 'first-keyframe-ready'),
              pending: shot.status === 'first-keyframe-pending',
              optionCount: shot.firstKeyframeOptions,
              chooseUrl: shot.links?.firstKeyframeChoose ?? '#',
              moreUrl: shot.links?.firstKeyframeMore ?? '#',
              adjustUrl: shot.links?.firstKeyframeAdjust,
              adjusting: shot.status === 'first-keyframe-ready' && isBusy(keyframes[0]?.status),
            }"
            :sequence="sequence"
            :presenter="shot.kind === 'presenter'"
            :render-seconds="shot.renderSeconds"
            :voice-over="shot.voiceOver"
            :video="{
              url: shot.videoUrl,
              downloadUrl: shot.videoDownloadUrl,
              error: shot.videoError,
              pending: shot.status === 'video-pending',
              submittedAt: shot.videoSubmittedAt,
              generateUrl: shot.links?.videoGenerate ?? '#',
              languages: shot.languageVideos,
              trim: shot.videoTrim,
              sent: shot.videoSent,
              trimUrl: shot.links?.videoTrim,
            }"
          />
        </template>
      </div>
    </main>
  </div>
</template>
<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3'
import EditorLayout from '@public/ts/layouts/Editor.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import KeyframesPanel, { type PanelKeyframe } from '@public:components/editor/KeyframesPanel.vue'
import { isBusy } from '@public:components/editor/keyframeStatus'
import MergedShot from '@public:components/editor/MergedShot.vue'
import PlanChat from '@public:components/editor/PlanChat.vue'
import PlanSkeleton from '@public:components/editor/PlanSkeleton.vue'
import { shotCode } from '@public:components/editor/shotCode'
import ShotList from '@public:components/editor/ShotList.vue'
import TopBar from '@public:components/editor/TopBar.vue'
import { index as projectsIndex } from '@routes/public/projects'
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

/** The shot's place in its sequence, with what the director can do about it. */
const sequence = computed(() =>
  props.shot.sequence
    ? {
        ...props.shot.sequence,
        confirmUrl: props.shot.links?.endFrameConfirm ?? '#',
        resetUrl: props.shot.links?.followersReset ?? '#',
        keepUrl: props.shot.links?.followersKeep ?? '#',
      }
    : null,
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
        staging: keyframe.staging,
        description: keyframe.description,
        spatial: keyframe.spatial ?? '',
        needsDescription: keyframe.needsDescription,
        imageUrl: keyframe.imageUrl,
        thumbnailUrl: keyframe.thumbnailUrl,
        status: keyframe.status,
        layout: keyframe.layout,
        layoutUrl: keyframe.links.layout,
        moveUrl: keyframe.links.move,
        renderError: keyframe.renderError,
        renders: keyframe.renders,
        elements: keyframe.elements,
        updateUrl: keyframe.links.update,
        tweakUrl: keyframe.links.tweak,
        chooseRenderUrl: keyframe.links.chooseRender,
      }))
    : (props.shot.storyline?.keyframes ?? []).map((keyframe, i) => ({
        id: String(i),
        title: keyframe.title,
        staging: { summary: keyframe.staging?.summary ?? '', place: '', cast: [] },
        description: keyframe.description,
        imageUrl: null,
        thumbnailUrl: null,
        status: props.shot.status === 'keyframes-pending' ? 'waiting' : 'planned',
        renderError: null,
        renders: [],
        elements: [],
        updateUrl: null,
        tweakUrl: null,
        chooseRenderUrl: null,
      })),
)

const busy = computed(
  () =>
    // Waiting for another shot or new cast pictures: drawing starts by itself, so the page keeps looking.
    Boolean(props.shot.waitingFor) ||
    planning.value ||
    (props.shot.planChat ?? []).some((turn) => turn.directing) ||
    props.shot.status === 'first-keyframe-pending' ||
    props.shot.status === 'keyframes-pending' ||
    props.shot.status === 'video-pending' ||
    props.shot.reviewing ||
    props.keyframes.some((keyframe) => isBusy(keyframe.status)) ||
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
