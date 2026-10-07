<template>
  <aside
    :class="
      cn(
        'flex shrink-0 flex-col border-r border-border transition-[width] duration-200',
        collapsed ? 'w-14 items-center gap-4 py-4' : 'w-[22rem] overflow-y-auto',
      )
    "
  >
    <template v-if="collapsed">
      <button
        type="button"
        class="min-h-0 truncate text-sm font-semibold text-muted-foreground [writing-mode:vertical-rl] hover:text-foreground"
        @click="toggle"
      >
        {{ shot.title }}
      </button>
      <Button
        type="button"
        variant="ghost"
        size="icon-sm"
        class="mt-auto"
        :aria-label="$t('Show the storyline')"
        :title="$t('Show the storyline')"
        @click="toggle"
      >
        <PanelLeftOpen class="size-4" />
      </Button>
    </template>

    <template v-else>
      <div class="flex items-start justify-between gap-3 p-6">
        <h2 class="text-2xl leading-tight font-semibold text-balance">{{ shot.title }}</h2>
        <div class="flex shrink-0 items-center">
          <!-- Back to the brief: submitting it again plans and draws the shot anew. -->
          <Button v-if="shot.links?.update" as-child variant="outline" size="sm">
            <Link :href="shot.links.update">
              <Pencil class="size-4" />
              {{ $t('Edit brief') }}
            </Link>
          </Button>
        </div>
      </div>

      <section class="space-y-2 border-t border-border p-6">
        <h3 class="flex items-center gap-2 font-semibold">
          <Target class="size-4 text-muted-foreground" />
          {{ $t('Takeaway') }}
        </h3>
        <p class="text-[15px] leading-relaxed">{{ shot.takeaway }}</p>
      </section>

      <section v-if="storyline && !inPlan" class="space-y-2 border-t border-border p-6">
        <h3 class="flex items-center gap-2 font-semibold">
          <ListOrdered class="size-4 text-muted-foreground" />
          {{ $t('Storyline') }}
        </h3>
        <p class="text-[15px] leading-relaxed text-muted-foreground">{{ storyline.storyline }}</p>
      </section>

      <section v-if="shot.rules.length > 0 && !inPlan" class="space-y-2 border-t border-border p-6">
        <h3 class="flex items-center gap-2 font-semibold">
          <ShieldCheck class="size-4 text-muted-foreground" />
          {{ $t('Rules for this shot') }}
        </h3>
        <ul class="list-inside list-disc space-y-1 text-[15px] leading-relaxed text-muted-foreground">
          <li v-for="rule in shot.rules" :key="rule">{{ rule }}</li>
        </ul>
      </section>

      <section v-if="storyline" class="space-y-2 border-t border-border p-6">
        <div class="flex items-center justify-between gap-3">
          <h3 class="flex items-center gap-2 font-semibold">
            <Mic class="size-4 text-muted-foreground" />
            {{ $t('Voice-over') }}
          </h3>
          <Button
            v-if="shot.voiceOver && !writing"
            type="button"
            variant="ghost"
            size="icon-sm"
            :aria-label="$t('Write the voice-over again')"
            :title="$t('Write the voice-over again')"
            @click="writeVoiceOver"
          >
            <RefreshCw class="size-4" />
          </Button>
        </div>
        <template v-if="shot.voiceOver && !writing">
          <p class="text-[15px] leading-relaxed">“{{ shot.voiceOver }}”</p>
          <p class="text-xs text-muted-foreground tabular-nums">
            {{
              $t('About :spoken s spoken, in a :total s video', {
                spoken: String(shot.voiceOverSeconds ?? 0),
                total: String(shot.seconds),
              })
            }}
          </p>
        </template>
        <div v-else-if="writing || waiting" class="space-y-2">
          <div class="h-3.5 w-full animate-pulse rounded bg-secondary" />
          <div class="h-3.5 w-2/3 animate-pulse rounded bg-secondary" />
        </div>
        <Button v-else type="button" variant="outline" size="sm" @click="writeVoiceOver">
          <Mic class="size-4" />
          {{ $t('Write the voice-over') }}
        </Button>
      </section>

      <section v-if="shot.voiceOverTracks.length > 0" class="space-y-3 border-t border-border p-6">
        <div class="flex items-center justify-between gap-3">
          <h3 class="flex items-center gap-2 font-semibold">
            <AudioLines class="size-4 text-muted-foreground" />
            {{ $t('Audio track') }}
          </h3>
          <Button
            v-if="shot.voiceOver"
            type="button"
            variant="ghost"
            size="sm"
            :disabled="anyTrackPending || regenerating"
            :title="$t('Speak every language again, for example after the voice-over changed')"
            @click="regenerateTracks"
          >
            <RefreshCw class="size-4" :class="(anyTrackPending || regenerating) && 'animate-spin'" />
            {{ anyTrackMissing ? $t('Generate all') : $t('Regenerate all') }}
          </Button>
        </div>
        <AudioTracks
          :tracks="shot.voiceOverTracks"
          :empty-hint="
            shot.videoUrl ? $t('Not spoken yet. Use Generate all to make it.') : $t('Made while the video renders.')
          "
        />
      </section>

      <section v-if="shot.links?.destroy" class="border-t border-border p-6">
        <ConfirmDelete
          :action="shot.links.destroy"
          :title="$t('Delete this shot?')"
          :description="
            $t(
              'The shot is removed from the sequence with its storyline, keyframes, video and audio tracks. This cannot be undone.',
            )
          "
          :confirm-label="$t('Delete shot')"
        >
          <template #trigger>
            <Button type="button" variant="outline" size="sm" class="text-destructive hover:text-destructive">
              <Trash2 class="size-4" />
              {{ $t('Delete shot') }}
            </Button>
          </template>
        </ConfirmDelete>
      </section>

      <div class="mt-auto flex justify-end px-6 pb-6">
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          :aria-label="$t('Hide the storyline')"
          :title="$t('Hide the storyline')"
          @click="toggle"
        >
          <PanelLeftClose class="size-4" />
        </Button>
      </div>
    </template>
  </aside>
</template>
<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import ConfirmDelete from '@public:components/ConfirmDelete.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import {
  AudioLines,
  ListOrdered,
  Mic,
  PanelLeftClose,
  PanelLeftOpen,
  Pencil,
  RefreshCw,
  ShieldCheck,
  Target,
  Trash2,
} from 'lucide-vue-next'
import { computed, onBeforeUnmount, ref, watch } from 'vue'

import AudioTracks from './AudioTracks.vue'

const props = defineProps<{
  shot: Inertia.Pages.Shots.View['shot']
  storyline: { title: string; storyline: string } | null
  /** The plan editor next to it shows the storyline and the rules, so they are not repeated here. */
  inPlan?: boolean
}>()

/** Remembered for this browser tab's session, so the column stays folded while moving between shots. */
const STORAGE_KEY = 'aidirector.shot-details.collapsed'

const read = (): boolean => {
  try {
    return window.sessionStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

const collapsed = ref(read())

const toggle = () => {
  collapsed.value = !collapsed.value

  try {
    window.sessionStorage.setItem(STORAGE_KEY, collapsed.value ? '1' : '0')
  } catch {
    // Storage can be blocked; the column still folds for this page.
  }
}

/** Asked for in this page; the text is written in the background and polled for. */
const writing = ref(false)

/** A planned shot gets its voice-over shortly after planning, so it is waited for. */
const waiting = computed(() => props.shot.voiceOver === null && props.shot.status === 'first-keyframe-pending')

let timer: ReturnType<typeof setInterval> | null = null

const stopPolling = () => {
  if (timer !== null) clearInterval(timer)
  timer = null
}

const startPolling = () => {
  stopPolling()
  let tries = 0
  timer = setInterval(() => {
    tries += 1
    if (tries > 20) {
      writing.value = false
      stopPolling()

      return
    }
    router.reload({ only: ['shot'] })
  }, 3000)
}

const writeVoiceOver = () => {
  if (!props.shot.links?.voiceOver) return

  writing.value = true
  router.post(
    props.shot.links.voiceOver,
    {},
    { preserveScroll: true, only: ['shot'], onSuccess: startPolling, onError: () => (writing.value = false) },
  )
}

watch(
  () => props.shot.voiceOver,
  (text) => {
    if (text) {
      writing.value = false
      stopPolling()
    }
  },
)

onBeforeUnmount(stopPolling)

const anyTrackMissing = computed(() => props.shot.voiceOverTracks.some((track) => track.status === 'missing'))

const anyTrackPending = computed(() => props.shot.voiceOverTracks.some((track) => track.status === 'pending'))

const regenerating = ref(false)

const regenerateTracks = () => {
  if (!props.shot.links?.voiceOverAudio) return

  regenerating.value = true
  router.post(
    props.shot.links.voiceOverAudio,
    {},
    { preserveScroll: true, only: ['shot'], onFinish: () => (regenerating.value = false) },
  )
}
</script>
