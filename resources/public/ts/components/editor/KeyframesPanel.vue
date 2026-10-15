<template>
  <div class="grid min-h-0 min-w-0 flex-1 grid-cols-[minmax(0,1fr)_auto] grid-rows-[minmax(0,1fr)_auto]">
    <section
      :class="
        cn(
          'group relative col-start-1 row-start-1 flex min-h-0 min-w-0 flex-col gap-6 p-6 pb-12',
          !sidePanel && 'col-span-2',
        )
      "
      @pointerdown="deselectOutside"
    >
      <div
        v-if="error && !generating"
        class="flex items-center justify-between gap-4 rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm"
      >
        <p>{{ error }}</p>
        <Button
          v-if="!choosing.active"
          type="button"
          variant="outline"
          size="sm"
          :disabled="retry.processing"
          @click="retryImages"
        >
          <RefreshCw class="size-4" :class="retry.processing && 'animate-spin'" />
          {{ $t('Try again') }}
        </Button>
      </div>

      <!-- A presenter speaks in every language of the project: one video each. -->
      <NativeSelect
        v-if="showVideo && languages.length > 1 && !video.pending"
        v-model="language"
        class="absolute top-4 left-4 z-10 w-44 bg-background/90"
        :aria-label="$t('Language')"
      >
        <option v-for="option in languages" :key="option.locale" :value="option.locale">{{ option.name }}</option>
      </NativeSelect>
      <!-- In the top right corner of the video area. -->
      <VideoActions
        v-if="!choosing.active && showVideo && currentVideo.url && !video.pending"
        :download-url="currentVideo.downloadUrl"
        class="absolute top-4 right-4 z-10"
      >
        <DropdownMenuItem :disabled="!canRenderVideo" @select="renderVideo">
          <RefreshCw class="size-4" />
          {{ $t('Render again') }}
        </DropdownMenuItem>
      </VideoActions>
      <FirstKeyframeChooser
        v-if="choosing.active && keyframes[0]"
        v-model:selected="selectedOption"
        :options="keyframes[0]?.renders ?? []"
        :option-count="choosing.optionCount"
        :aspect-ratio="aspectRatio"
        :pending="choosing.pending"
        :choose-url="choosing.chooseUrl"
        :more-url="choosing.moreUrl"
        :adjusting="choosing.adjusting"
      />
      <div v-else class="flex min-h-0 flex-1">
        <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-6">
          <!-- Select and Move of the keyframe shown are put here, centred above it. -->
          <div
            v-if="!showVideo && !adding && selected?.imageUrl && selected.moveUrl"
            id="keyframe-tools"
            data-keeps-selection
            class="flex shrink-0 items-center justify-center"
          />
          <div
            class="relative flex min-h-0 min-w-0 flex-1 items-center justify-center"
            :style="{ containerType: 'size' }"
          >
            <!-- What the selected keyframe shows, centred in the space left of the image; a presenter's is what they say. -->
            <div
              v-if="!showVideo && !adding && selected && isPortrait"
              class="absolute inset-y-0 left-0 flex items-center justify-center px-6"
              :style="{ width: sideWidth }"
            >
              <div data-keeps-selection class="max-h-full w-full max-w-64 overflow-y-auto py-6">
                <div v-if="presenter" class="space-y-2 text-[15px] leading-relaxed">
                  <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                    {{ $t('Voice-over') }}
                  </p>
                  <p class="text-muted-foreground italic">{{ voiceOver || $t('No voice-over yet.') }}</p>
                </div>
                <KeyframeStagingForm
                  v-else
                  :key="selected.id"
                  :keyframe="selected"
                  :index="selectedIndex"
                  :selected-member="selectedMember"
                  :following="followingCount[selected.id] ?? 0"
                  :disabled="busy(selected)"
                />
              </div>
            </div>
            <video
              v-if="showVideo && currentVideo.url"
              :key="currentVideo.url"
              data-shot-video
              :src="currentVideo.url"
              controls
              autoplay
              loop
              playsinline
              class="max-h-full max-w-full rounded-xl border border-border bg-black object-cover"
              :style="frameSize"
            />
            <div
              v-else-if="!showVideo && !adding && selected?.imageUrl"
              data-keeps-selection
              class="relative flex max-h-full max-w-full items-end justify-center"
              :style="frameSize"
            >
              <img
                :src="selected.imageUrl"
                :alt="selected.title"
                :class="
                  cn(
                    'size-full rounded-xl border border-border bg-card object-cover',
                    selected.status === 'waiting' && 'opacity-50',
                  )
                "
              />
              <span class="pointer-events-none absolute inset-0 overflow-hidden rounded-xl">
                <RenderProgress
                  :progress-key="`keyframe:${selected.id}`"
                  :active="drawing(selected)"
                  :seconds="renderSeconds.keyframe"
                  :image-url="selected.imageUrl"
                />
              </span>
              <p
                v-if="selected.status === 'waiting' || selected.status === 'checking'"
                class="absolute bottom-4 mx-4 flex items-center gap-2 rounded-lg border border-border bg-background/90 px-4 py-3 text-sm text-signal"
              >
                <LoaderCircle class="size-4 shrink-0 animate-spin" />
                {{ renderLabel(selected) }}
              </p>
              <KeyframeTools
                v-if="selected.moveUrl"
                :key="selected.id"
                v-model:selected="selectedMember"
                :layout="selected.layout ?? []"
                :layout-url="selected.layoutUrl ?? null"
                :move-url="selected.moveUrl"
                :following="followingCount[selected.id] ?? 0"
                :disabled="busy(selected)"
              />
            </div>
            <!-- The video is made from the keyframes: the line runs over keyframe 1 until it is there. -->
            <div
              v-else-if="showVideo && video.pending"
              class="relative max-h-full max-w-full overflow-hidden rounded-xl border border-border bg-card"
              :style="frameSize"
            >
              <RenderProgress
                :progress-key="`video:${video.generateUrl}`"
                :active="video.pending"
                :seconds="renderSeconds.video"
                :started-at="video.submittedAt"
                :image-url="keyframes[0]?.imageUrl"
              />
            </div>
            <Placeholder v-else class="max-h-full max-w-full rounded-xl bg-card" :style="frameSize">
              <p
                v-if="showVideo"
                class="rounded-lg border border-border bg-background/90 px-4 py-3 text-sm text-muted-foreground"
              >
                {{ $t('No video yet. Render it from the keyframes.') }}
              </p>
              <p
                v-else-if="adding"
                class="rounded-lg border border-border bg-background/90 px-4 py-3 text-sm text-muted-foreground"
              >
                {{ $t('New keyframe :n. Describe it on the right.', { n: String(keyframes.length + 1) }) }}
              </p>
              <div
                v-else-if="selected"
                class="max-w-md space-y-2 rounded-lg border border-border bg-background/90 px-5 py-4 text-center"
              >
                <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                  {{ $t('Keyframe :n of :total', { n: String(selectedIndex + 1), total: String(keyframes.length) }) }}
                </p>
                <!-- The title and description are in the column on the right. -->
                <p v-if="busy(selected)" class="flex items-center justify-center gap-2 pt-1 text-xs text-signal">
                  <LoaderCircle class="size-3.5 animate-spin" />
                  {{ renderLabel(selected) }}
                </p>
              </div>
            </Placeholder>
          </div>
        </div>
      </div>
    </section>

    <!-- The conversation about the shot goes on here; the panels below stay for now, hidden. -->
    <!-- The chat, or the new keyframe's form; without a video column it runs the full height. -->
    <div
      v-if="sidePanel"
      :class="cn('relative col-start-2 row-start-1 flex min-h-0', !showVideoColumn && 'row-span-2')"
    >
      <!-- Drag to make the column wider or narrower; the width is remembered. -->
      <button
        type="button"
        class="group/resize absolute top-[50px] -left-[17px] z-10 flex h-10 w-6 -translate-y-1/2 cursor-col-resize touch-none items-center justify-center"
        :aria-label="$t('Resize the panel')"
        @pointerdown="startResize"
        @pointermove="resize"
        @pointerup="stopResize"
        @pointercancel="stopResize"
        @dblclick="resetWidth"
      >
        <svg
          viewBox="0 0 4 6"
          class="h-[18px] w-3 fill-border transition-colors group-hover/resize:fill-muted-foreground"
          aria-hidden="true"
        >
          <path d="M4 0 0 3l4 3Z" />
        </svg>
      </button>
      <aside
        v-if="chat && !adding && !showVideo"
        class="flex shrink-0 flex-col border-l border-border px-5 py-4"
        :style="{ width: `${columnWidth}px` }"
      >
        <PlanChat
          :key="`chat`"
          :chat-url="chat.url"
          :conversation="chat.conversation"
          :kinds="chat.kinds"
          :elements="chat.elements"
          :new-elements="chat.newElements"
          :elements-url="chat.elementsUrl"
          :waiting-for="null"
          :target="chatTarget"
          :change-url="chat.changeUrl"
          :voice-over-url="chat.voiceOverUrl"
          :following="followingCount"
          :issues="!choosing.active && selected && !busy(selected) ? issuesFor(selected.id) : null"
        />
      </aside>
      <FirstKeyframeInspector
        v-if="showInspectors && choosing.active && keyframes[0]"
        :keyframe="keyframes[0]"
        :adjust-url="choosing.adjustUrl"
        :selected="selectedOption"
        :busy="choosing.pending || Boolean(choosing.adjusting)"
      />
      <NewKeyframeInspector
        v-else-if="adding"
        :position="keyframes.length + 1"
        :store-url="newKeyframe.storeUrl"
        @added="adding = false"
      />
      <KeyframeInspector
        v-else-if="showInspectors && selected && !showVideo"
        :key="selected.id"
        :keyframe="selected"
        :index="selectedIndex"
        :can-delete="canArrange"
        :can-copy="keyframes.length < newKeyframe.max"
        :issues="issuesFor(selected.id)"
      />
    </div>

    <section
      :class="
        cn(
          'col-start-1 row-start-2 min-w-0 space-y-4 border-t border-border p-6',
          !sidePanel && !showVideoColumn && 'col-span-2',
        )
      "
    >
      <p
        v-if="reviewing && !generating"
        class="flex items-center gap-2 rounded-lg border border-border bg-card px-4 py-3 text-sm text-signal"
      >
        <LoaderCircle class="size-4 animate-spin" />
        {{ $t('Reviewing the keyframes together…') }}
      </p>
      <!-- Only for what concerns the whole shot; a keyframe with something found is marked in the strip and explained when selected. -->
      <div
        v-else-if="shotIssues.length > 0 && !generating"
        class="space-y-1 rounded-lg border border-amber-500/50 bg-amber-500/10 px-3 py-2 text-sm"
      >
        <div class="flex items-center gap-3">
          <TriangleAlert class="size-4 shrink-0 text-amber-500" />
          <p class="min-w-0 flex-1">{{ $t('The check found something in the whole shot') }}</p>
          <Button v-if="fixAllUrl" type="button" size="sm" :disabled="fixing.processing" @click="fixAll">
            <Wand class="size-4" />
            {{ $t('Fix all') }}
          </Button>
          <Button
            v-if="dismissAllUrl"
            type="button"
            size="sm"
            variant="ghost"
            :disabled="fixing.processing"
            @click="dismissAll"
          >
            {{ $t('Dismiss all') }}
          </Button>
        </div>
        <p v-for="(issue, i) in shotIssues" :key="i" class="pl-7 text-muted-foreground">{{ issue }}</p>
      </div>

      <ul class="flex items-start gap-4 overflow-x-auto pb-1">
        <li
          v-for="(keyframe, i) in strip"
          :key="keyframe.id"
          :draggable="canArrange"
          :class="
            cn(
              'w-44 shrink-0 space-y-2 transition-opacity',
              canArrange && 'cursor-grab active:cursor-grabbing',
              keyframe.id === draggingId && 'opacity-40',
            )
          "
          :title="canArrange ? $t('Drag to change the order') : undefined"
          @dragstart="startDrag($event, keyframe.id)"
          @dragover.prevent="dragOver(keyframe.id)"
          @drop.prevent="drop"
          @dragend="endDrag"
        >
          <button
            type="button"
            :class="
              cn(
                'relative block h-28 w-full overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-muted-foreground/60',
                isSelected(keyframe.id) && 'border-signal ring-2 ring-signal/40',
              )
            "
            @click="selectById(keyframe.id)"
          >
            <img
              v-if="keyframe.thumbnailUrl"
              :src="keyframe.thumbnailUrl"
              :alt="keyframe.title"
              draggable="false"
              :class="cn('size-full object-cover', keyframe.status === 'waiting' && 'opacity-50')"
            />
            <Placeholder v-else class="size-full rounded-none border-0 bg-card" />
            <RenderProgress
              :progress-key="`keyframe:${keyframe.id}`"
              :active="drawing(keyframe)"
              :seconds="renderSeconds.keyframe"
              :image-url="keyframe.thumbnailUrl"
              compact
            />
            <span
              v-if="keyframe.status === 'waiting'"
              class="absolute inset-0 flex items-center justify-center bg-background/40 text-signal"
            >
              <LoaderCircle class="size-5 animate-spin" />
            </span>
            <!-- A light shade over the image, so the warning stands out. -->
            <span
              v-else-if="!drawing(keyframe) && issuesFor(keyframe.id)"
              class="absolute inset-0 bg-black/10"
              :title="$t('The check found something here')"
            >
              <span
                class="absolute top-1.5 left-1.5 flex size-6 items-center justify-center rounded-full bg-amber-500 text-black shadow"
              >
                <TriangleAlert class="size-3.5" />
              </span>
            </span>
          </button>
          <p class="px-1 text-sm text-muted-foreground tabular-nums">{{ timeAt(i) }}</p>
        </li>
        <!-- A presenter speaks from one still. -->
        <li v-if="!presenter" class="w-44 shrink-0">
          <button
            type="button"
            :disabled="!canAdd"
            :class="
              cn(
                'flex h-28 w-full items-center justify-center rounded-lg border border-dashed border-border bg-card/60 text-muted-foreground transition-colors enabled:hover:border-muted-foreground/60 enabled:hover:text-foreground disabled:opacity-50',
                adding && 'border-solid border-signal text-signal ring-2 ring-signal/40',
              )
            "
            @click="startAdding"
          >
            <Plus class="size-6" />
          </button>
          <p :class="cn('mt-2 px-1 text-sm text-muted-foreground', adding && 'font-semibold text-foreground')">
            {{ $t('Add keyframe') }}
          </p>
        </li>
      </ul>
      <InputError :message="arrangeError" />
    </section>

    <!-- Shown once there is a video, or once one can be rendered; until then the keyframes take the width. -->
    <section
      v-if="showVideoColumn"
      class="col-start-2 row-start-2 flex flex-col gap-4 border-t border-l border-border p-6"
      :style="{ width: `${columnWidth}px` }"
    >
      <p
        v-if="video.error && !video.pending"
        class="rounded-lg border border-destructive/50 bg-destructive/10 px-3 py-2 text-sm"
      >
        {{ video.error }}
      </p>
      <InputError :message="videoForm.errors.video" />

      <div class="space-y-2">
        <div
          :class="
            cn(
              'relative flex h-28 w-full items-center justify-center overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-muted-foreground/60',
              showVideo && 'border-signal ring-2 ring-signal/40',
            )
          "
        >
          <video
            v-if="video.url"
            :key="video.url"
            :src="`${video.url}#t=0.1`"
            preload="metadata"
            muted
            playsinline
            class="absolute inset-0 size-full object-cover"
          />
          <Placeholder v-else class="absolute inset-0 size-full rounded-none border-0 bg-card" />
          <button
            type="button"
            class="absolute inset-0 flex items-center justify-center"
            :aria-label="$t('Show the video')"
            @click="selectVideo"
          >
            <RenderProgress
              :progress-key="`video:${video.generateUrl}`"
              :active="video.pending"
              :seconds="renderSeconds.video"
              :started-at="video.submittedAt"
              :image-url="keyframes[0]?.thumbnailUrl"
              compact
            />
            <span
              v-if="!video.pending && video.url"
              class="flex size-11 items-center justify-center rounded-full bg-background/60 backdrop-blur-sm"
            >
              <Play class="size-5 fill-current" />
            </span>
          </button>
          <Button
            v-if="!video.url && !video.pending"
            type="button"
            class="relative"
            :disabled="!canRenderVideo"
            @click="renderVideo"
          >
            <LoaderCircle v-if="videoForm.processing" class="size-4 animate-spin" />
            <Clapperboard v-else class="size-4" />
            {{ $t('Render video') }}
          </Button>
        </div>
        <p class="px-1 text-sm text-muted-foreground tabular-nums">
          {{ video.pending ? $t('Rendering…') : `${duration} s` }}
        </p>
      </div>
    </section>
  </div>
</template>
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { DropdownMenuItem } from '@shared:ui/dropdown-menu'
import { NativeSelect } from '@shared:ui/native-select'
import { Clapperboard, LoaderCircle, Play, Plus, RefreshCw, TriangleAlert, Wand } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

import FirstKeyframeChooser from './FirstKeyframeChooser.vue'
import FirstKeyframeInspector from './FirstKeyframeInspector.vue'
import KeyframeInspector from './KeyframeInspector.vue'
import KeyframeStagingForm from './KeyframeStagingForm.vue'
import { isBusy, type KeyframeStatus } from './keyframeStatus'
import KeyframeTools, { type LayoutMember } from './KeyframeTools.vue'
import NewKeyframeInspector from './NewKeyframeInspector.vue'
import Placeholder from './Placeholder.vue'
import PlanChat, { type ChatTarget, type PlanChatTurn } from './PlanChat.vue'
import RenderProgress from './RenderProgress.vue'
import VideoActions from './VideoActions.vue'

export type PanelIssueGroup = {
  key: string
  position: number | null
  issues: string[]
  fixUrl: string | null
  /** Draws the keyframe again from its description instead of fixing it. */
  redrawUrl?: string | null
  dismissUrl: string
}

export type KeyframeStagingFields = {
  summary: string
  place: string
  cast: { name: string; stands: string; faces: string; looks_at: string; holds: string; hand: string; doing: string }[]
}

export type PanelKeyframe = {
  id: string
  title: string
  /** How the keyframe is staged: the short line, the place and per person of the cast how they stand. */
  staging: KeyframeStagingFields
  /** What the image model gets, built from the staging. */
  description: string
  /** The one fact a viewer must see at a glance; sent as the last sentence of the description. */
  spatial?: string
  /** A copy that still repeats another keyframe's description. */
  needsDescription?: boolean
  imageUrl: string | null
  thumbnailUrl: string | null
  status: KeyframeStatus
  /** Where the people and objects stand, to pick one and move it. */
  layout?: LayoutMember[]
  /** Reading where they stand, for a keyframe without a layout yet. */
  layoutUrl?: string | null
  /** Moving one of them; only on a drawn keyframe of a scene. */
  moveUrl?: string | null
  renderError: string | null
  renders: {
    id: number
    chosen: boolean
    imageUrl: string
    thumbnailUrl: string
    /** What the director asked for, when this version came from an adjustment. */
    request?: string | null
    /** The request came from the automatic check, through Fix. */
    requestFromCheck?: boolean
    /** The rewritten instruction the image model received. */
    instruction?: string | null
    /** When the automatic check redrew this version: what was wrong with the one before. */
    /** What the keyframe must show that the check still could not see after a redraw. */
    /** What the check found wrong with this version, kept as notes. */
    checkIssues?: string[]
    /** What the image model got for this version. */
    sent?: { model: string; prompt: string; images: string[] } | null
    /** The share of the background that stayed in place, measured in code. */
    stillness?: number | null
    /** After a person was moved: why the image no longer fits the keyframe's point in the story. */
  }[]
  elements: string[]
  updateUrl: string | null
  tweakUrl: string | null
  chooseRenderUrl: string | null
  destroyUrl: string | null
  copyUrl?: string | null
}

export type PanelChoosing = {
  active: boolean
  pending: boolean
  optionCount: number
  chooseUrl: string
  moreUrl: string
  adjustUrl?: string
  adjusting?: boolean
}

export type PanelNewKeyframe = {
  storeUrl: string
  max: number
}

export type PanelVideo = {
  url: string | null
  downloadUrl?: string | null
  error: string | null
  pending: boolean
  generateUrl: string
  /** When the video was handed to the video model, so its progress continues after a reload. */
  submittedAt?: string | null
  /** A presenter's video per language, each with its own sound. */
  languages?: { locale: string; name: string; url: string | null; downloadUrl: string | null }[]
}

const props = defineProps<{
  /** The conversation about the shot, which goes on next to the drawn images. */
  chat?: {
    url?: string
    conversation?: PlanChatTurn[]
    kinds: { value: string; label: string; description: string }[]
    elements: { id: string; type: string; name: string; imageUrl: string | null; rendering?: boolean }[]
    newElements?: { name: string; type: string; description: string }[]
    elementsUrl?: string
    /** Makes or leaves a change the chat proposed for one of the cast. */
    changeUrl?: string
    /** Uses or declines a voice-over the chat suggested. */
    voiceOverUrl?: string
  }
  keyframes: PanelKeyframe[]
  aspectRatio: string
  duration: number
  generating: boolean
  error?: string | null
  /** Tries again what failed: the options for keyframe 1, or only the keyframes that could not be drawn. */
  retryUrl: string
  video: PanelVideo
  choosing: PanelChoosing
  /** How long a keyframe and a video usually take, in seconds, for the progress line. */
  renderSeconds: { keyframe: number; video: number }
  /** One person speaks the voice-over to the camera, with a video per language. */
  presenter?: boolean
  /** What the presenter says. */
  voiceOver?: string | null
  newKeyframe: PanelNewKeyframe
  reorderUrl: string
  /** What the checks found, per keyframe position or for the whole shot (position null). */
  issueGroups?: PanelIssueGroup[]
  /** The keyframes are being reviewed together right now. */
  reviewing?: boolean
  /** Redraws every keyframe the notes are about, when there is one to redraw. */
  fixAllUrl?: string | null
  /** Takes every note off the list without changing anything. */
  dismissAllUrl?: string | null
}>()

const selectedIndex = ref(0)

/** The keyframe 1 option the director selected, shared by the options and the column that adjusts them. */
const selectedOption = ref<number | null>(null)

/** The inspectors of a keyframe and of keyframe 1 are kept, but the chat takes their place. */
const showInspectors = false

/** The one of the cast selected in the keyframe, whom the chat is about. */
const selectedMember = ref<string | null>(null)

/** A click in the keyframe area off the image, its tools and its fields lets go of the one selected. */
const deselectOutside = (event: PointerEvent) => {
  if ((event.target as HTMLElement | null)?.closest('[data-keeps-selection]')) return

  selectedMember.value = null
}

/** What the chat is about: the selected option while choosing keyframe 1, otherwise the selected keyframe. */
const chatTarget = computed<ChatTarget>(() => {
  if (props.choosing.active) {
    if (selectedOption.value === null) return null

    const number = (props.keyframes[0]?.renders ?? []).findIndex((option) => option.id === selectedOption.value) + 1

    return {
      kind: 'option',
      option: selectedOption.value,
      label: $t('About option :n for keyframe 1', { n: String(number) }),
    }
  }

  if (!selected.value) return null

  const n = String(selectedIndex.value + 1)

  return selectedMember.value
    ? {
        kind: 'keyframe',
        keyframe: selected.value.id,
        member: selectedMember.value,
        label: $t('About :name in keyframe :n', { name: selectedMember.value, n }),
      }
    : {
        kind: 'keyframe',
        keyframe: selected.value.id,
        label: $t('About keyframe :n · :title', { n, title: selected.value.title }),
      }
})

/** Per keyframe, how many drawn keyframes come after it; they may be drawn again to follow a change. */
const followingCount = computed<Record<string, number>>(() =>
  Object.fromEntries(
    props.keyframes.map((keyframe, index) => [
      keyframe.id,
      props.keyframes.filter((later, laterIndex) => laterIndex > index && later.imageUrl).length,
    ]),
  ),
)

const showVideo = ref(Boolean(props.video.url || props.video.pending))

const languages = computed(() => props.video.languages ?? [])
const language = ref<string | null>(languages.value[0]?.locale ?? null)

/** The video shown and downloaded: the chosen language of a presenter, otherwise the shot's video. */
const currentVideo = computed(() => {
  const chosen = languages.value.find((option) => option.locale === language.value) ?? languages.value[0]

  return chosen
    ? { url: chosen.url, downloadUrl: chosen.downloadUrl }
    : { url: props.video.url, downloadUrl: props.video.downloadUrl }
})

const adding = ref(false)

const selectKeyframe = (index: number) => {
  selectedIndex.value = index
  showVideo.value = false
  adding.value = false
}

/**
 * Selects the empty slot at the end of the strip; the right column becomes the form for it.
 */
const startAdding = () => {
  adding.value = true
  showVideo.value = false
}

watch(
  () => props.video.pending,
  (pending) => {
    if (pending) showVideo.value = true
  },
)

watch(
  () => props.video.url,
  (url) => {
    if (url) showVideo.value = true
  },
)

watch(
  () => props.keyframes.length,
  (length, previous) => {
    if (previous !== undefined && length > previous && !props.choosing.active) {
      selectKeyframe(length - 1)
    } else if (selectedIndex.value >= length) {
      selectedIndex.value = Math.max(length - 1, 0)
    }
  },
)

const selected = computed(() => props.keyframes[selectedIndex.value])

watch(
  () => props.choosing.active,
  (active) => {
    if (active) selectKeyframe(0)
  },
  { immediate: true },
)

/*
 * The selection lives in the URL (?keyframe=<id> or ?view=video), so a
 * refresh or a shared link opens the same keyframe or the video.
 */
const urlSelection = typeof window === 'undefined' ? null : new URLSearchParams(window.location.search)

if (urlSelection && !props.choosing.active) {
  const fromUrl = props.keyframes.findIndex((keyframe) => keyframe.id === urlSelection.get('keyframe'))

  if (urlSelection.get('view') === 'video' && (props.video.url || props.video.pending)) {
    showVideo.value = true
  } else if (fromUrl >= 0) {
    selectKeyframe(fromUrl)
  }
}

watch(
  () => [showVideo.value, selected.value?.id] as const,
  ([video, id]) => {
    if (typeof window === 'undefined') return

    const url = new URL(window.location.href)
    url.searchParams.delete('keyframe')
    url.searchParams.delete('view')

    if (video) {
      url.searchParams.set('view', 'video')
    } else if (id && !props.choosing.active) {
      url.searchParams.set('keyframe', id)
    }

    const next = url.pathname + url.search

    if (next !== window.location.pathname + window.location.search) {
      router.replace({ url: next, preserveState: true, preserveScroll: true })
    }
  },
)

/** The space left of a portrait image, where what the keyframe shows is written. */
const sideWidth = computed(() => {
  const [w, h] = props.aspectRatio.split(':').map(Number)

  return `max(0px, calc(50% - ${w / h} * 50cqh))`
})

const isPortrait = computed(() => {
  const [w, h] = props.aspectRatio.split(':').map(Number)

  return h >= w
})

/**
 * One side fills the stage and the ratio sets the other, so the frame hugs
 * the image or video instead of letterboxing it.
 */
const frameSize = computed(() => ({
  aspectRatio: props.aspectRatio.replace(':', ' / '),
  height: isPortrait.value ? '100%' : undefined,
  width: isPortrait.value ? undefined : '100%',
}))

const timeAt = (index: number) => {
  const count = Math.max(props.keyframes.length - 1, 1)

  return `${((props.duration * index) / count).toFixed(1)} s`
}

const selectVideo = () => {
  showVideo.value = true
  adding.value = false
}

const canAdd = computed(
  () =>
    !props.choosing.active &&
    !props.generating &&
    !props.video.pending &&
    props.keyframes.length > 0 &&
    props.keyframes.length < props.newKeyframe.max &&
    props.keyframes.every((keyframe) => keyframe.imageUrl && keyframe.updateUrl && !busy(keyframe)),
)

/**
 * Keyframes can be dragged into a new order or deleted once all of them are
 * drawn and nothing else is being generated.
 */
const canArrange = computed(
  () =>
    !props.choosing.active &&
    !props.generating &&
    !props.video.pending &&
    !arranging.value &&
    props.keyframes.length > 1 &&
    props.keyframes.every((keyframe) => keyframe.imageUrl && keyframe.destroyUrl && !busy(keyframe)),
)

const busy = (keyframe: PanelKeyframe) => isBusy(keyframe.status)

/** Being drawn right now, shown with the line that runs over the image; while it is checked the image stays as it is. */
const drawing = (keyframe: PanelKeyframe) => keyframe.status === 'drawing'

/**
 * What a keyframe that is not ready yet is doing, so a longer wait explains itself; null when there is nothing to say.
 */
const renderLabel = (keyframe: PanelKeyframe): string | null => {
  switch (keyframe.status) {
    case 'waiting':
      return $t('Waits for the keyframe before')
    case 'drawing':
      return $t('Generating image…')
    case 'checking':
      return $t('Checking image…')
    default:
      return null
  }
}

const isSelected = (id: string) => !showVideo.value && !adding.value && selected.value?.id === id

const selectById = (id: string) => selectKeyframe(props.keyframes.findIndex((keyframe) => keyframe.id === id))

const draggingId = ref<string | null>(null)
const dragOrder = ref<string[]>([])
const arranging = ref(false)
const arrangeError = ref<string | undefined>()

/**
 * While dragging, and until the server confirms, the strip shows the order being dragged.
 */
const strip = computed(() => {
  if (draggingId.value === null && !arranging.value) return props.keyframes

  return dragOrder.value
    .map((id) => props.keyframes.find((keyframe) => keyframe.id === id))
    .filter((keyframe): keyframe is PanelKeyframe => keyframe !== undefined)
})

const startDrag = (event: DragEvent, id: string) => {
  if (!canArrange.value) {
    event.preventDefault()

    return
  }

  draggingId.value = id
  dragOrder.value = props.keyframes.map((keyframe) => keyframe.id)
  arrangeError.value = undefined

  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', id)
  }
}

const dragOver = (overId: string) => {
  const dragged = draggingId.value

  if (dragged === null || dragged === overId) return

  const order = dragOrder.value.filter((id) => id !== dragged)
  order.splice(dragOrder.value.indexOf(overId), 0, dragged)
  dragOrder.value = order
}

const drop = () => {
  const dragged = draggingId.value
  const selectedId = selected.value?.id
  draggingId.value = null

  if (dragged === null) return

  const unchanged = dragOrder.value.every((id, index) => props.keyframes[index]?.id === id)

  if (unchanged) return

  arranging.value = true
  router.post(
    props.reorderUrl,
    { keyframes: dragOrder.value },
    {
      preserveScroll: true,
      onSuccess: () => {
        const index = props.keyframes.findIndex((keyframe) => keyframe.id === selectedId)
        if (index >= 0) selectedIndex.value = index
      },
      onError: (errors) => (arrangeError.value = errors.keyframes),
      onFinish: () => (arranging.value = false),
    },
  )
}

const endDrag = () => {
  if (draggingId.value !== null) drop()
}

const retry = useForm({})
const issueGroups = computed(() => props.issueGroups ?? [])
/** What concerns the whole shot; a failed video shows in the video column instead. */
const shotIssues = computed(() =>
  (issueGroups.value.find((group) => group.position === null)?.issues ?? []).filter(
    (issue) => issue !== props.video.error,
  ),
)

/** The issues of a keyframe, found by its position in the shot. */
const issuesFor = (id: string) => {
  const position = props.keyframes.findIndex((keyframe) => keyframe.id === id) + 1

  return issueGroups.value.find((group) => group.position === position) ?? null
}

const fixing = useForm({})
const fixAll = () => props.fixAllUrl && fixing.post(props.fixAllUrl, { preserveScroll: true })
const dismissAll = () => props.dismissAllUrl && fixing.post(props.dismissAllUrl, { preserveScroll: true })
const videoForm = useForm<{ video?: string }>({})

const canRenderVideo = computed(
  () =>
    !props.generating &&
    !props.video.pending &&
    !videoForm.processing &&
    props.keyframes.length > 0 &&
    props.keyframes.every((keyframe) => keyframe.imageUrl && !busy(keyframe)),
)

const renderVideo = () => videoForm.post(props.video.generateUrl, { preserveScroll: true })

/** The width of the column with the chat and the video, dragged by the director and remembered in this browser. */
const WIDTH_KEY = 'editor-column-width'
const DEFAULT_WIDTH = 352
const clampWidth = (width: number) => Math.round(Math.min(720, Math.max(280, width)))
const readWidth = () => {
  try {
    const stored = Number(localStorage.getItem(WIDTH_KEY))

    return stored > 0 ? clampWidth(stored) : DEFAULT_WIDTH
  } catch {
    return DEFAULT_WIDTH
  }
}
const columnWidth = ref(typeof window === 'undefined' ? DEFAULT_WIDTH : readWidth())
const resizing = ref<{ x: number; width: number } | null>(null)

const startResize = (event: PointerEvent) => {
  resizing.value = { x: event.clientX, width: columnWidth.value }
  ;(event.currentTarget as HTMLElement).setPointerCapture(event.pointerId)
}

// The column sits on the right, so dragging to the left makes it wider.
const resize = (event: PointerEvent) => {
  if (resizing.value) columnWidth.value = clampWidth(resizing.value.width + resizing.value.x - event.clientX)
}

const rememberWidth = () => {
  try {
    localStorage.setItem(WIDTH_KEY, String(columnWidth.value))
  } catch {
    // Without storage the width lasts until the page is left.
  }
}

const stopResize = () => {
  if (!resizing.value) return

  resizing.value = null
  rememberWidth()
}

const resetWidth = () => {
  columnWidth.value = DEFAULT_WIDTH
  rememberWidth()
}

/** The chat, or the form of a new keyframe, next to the image. */
const sidePanel = computed(() => adding.value || Boolean(props.chat && !showVideo.value))

const showVideoColumn = computed(
  () => Boolean(props.video.url) || props.video.pending || Boolean(props.video.error) || canRenderVideo.value,
)

const retryImages = () => retry.post(props.retryUrl, { preserveScroll: true })
</script>
