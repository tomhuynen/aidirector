<template>
  <form class="space-y-5" @submit.prevent="redraw">
    <!-- What happens, while no one of the cast is selected; selecting someone shows their fields instead. -->
    <div v-if="!editingMember" class="flex gap-2 text-[15px] leading-relaxed">
      <!-- Opens what the image model got for the image shown. -->
      <button
        type="button"
        class="group/prompt -ml-1 flex h-4 w-4 shrink-0 cursor-pointer items-center justify-center"
        :aria-expanded="showPrompt"
        :aria-label="$t('What the image model got')"
        :title="$t('What the image model got')"
        @click="showPrompt = !showPrompt"
      >
        <svg
          viewBox="0 0 4 6"
          :class="
            cn(
              'h-3 w-2 fill-border transition-[fill,rotate] group-hover/prompt:fill-muted-foreground',
              showPrompt && 'rotate-90',
            )
          "
          aria-hidden="true"
        >
          <path d="M0 0l4 3-4 3Z" />
        </svg>
      </button>
      <div class="min-w-0 space-y-2">
        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
          {{ $t('Keyframe :n · :title', { n: String(index + 1), title: keyframe.title }) }}
        </p>
        <p class="text-muted-foreground">{{ keyframe.staging.summary || keyframe.description }}</p>
        <div v-if="showPrompt" class="space-y-2 border-t border-border pt-3 text-sm">
          <template v-if="sent">
            <p class="text-xs text-muted-foreground">{{ sent.model }}</p>
            <p class="whitespace-pre-line text-muted-foreground">{{ sent.prompt }}</p>
            <ol v-if="sent.images.length" class="space-y-0.5 text-xs text-muted-foreground">
              <li v-for="(image, i) in sent.images" :key="i">
                {{ $t('Image :n: :what', { n: String(i + 1), what: image }) }}
              </li>
            </ol>
          </template>
          <p v-else class="text-muted-foreground">{{ $t('Not recorded for this image.') }}</p>
        </div>
      </div>
    </div>

    <template v-if="editable">
      <!-- The selected one's staging; the chat changes it. -->
      <section
        v-for="member in form.cast.filter((member) => member.name === editingMember)"
        :key="member.name"
        class="space-y-3"
      >
        <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ member.name }}</p>
        <dl class="space-y-2 text-[15px] leading-relaxed">
          <div v-for="field in memberFields(member)" :key="field.label">
            <dt class="text-xs text-muted-foreground">{{ field.label }}</dt>
            <dd>{{ field.value }}</dd>
          </div>
        </dl>
        <p class="text-sm text-muted-foreground">{{ $t('Tell the chat what should change about them.') }}</p>
      </section>
    </template>

    <FollowingDialog v-model:open="asking" :count="following" :processing="form.processing" @answer="send" />
  </form>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { computed, ref, watch } from 'vue'

import FollowingDialog from './FollowingDialog.vue'
import type { PanelKeyframe } from './KeyframesPanel.vue'

/**
 * How the selected keyframe is staged, in fields: what happens, and, for
 * the one of the cast selected in the image, where they stand, which way
 * they face, what they look at, hold and do, to read; the chat changes
 * those. The place stays as it was chosen. The text the image model gets is built from them. Once something
 * changed, Redraw adjusts the image to it.
 */
const props = defineProps<{
  keyframe: PanelKeyframe
  index: number
  /** The one selected in the image; when they are of the cast, their fields replace what happens. */
  selectedMember: string | null
  /** How many drawn keyframes come after this one; they may be drawn again to follow the change. */
  following: number
  /** The keyframe is being drawn: the fields wait. */
  disabled?: boolean
}>()

const fieldsOf = (keyframe: PanelKeyframe) => ({
  summary: keyframe.staging.summary,
  place: keyframe.staging.place,
  cast: keyframe.staging.cast.map((member) => ({ ...member })),
  spatial: keyframe.spatial ?? '',
})

const form = useForm({ ...fieldsOf(props.keyframe), redraw: false, following: false })

const editable = computed(() => Boolean(props.keyframe.updateUrl))

/** What the image model got for the image shown: its model, prompt and attached images. */
const showPrompt = ref(false)
const sent = computed(() => props.keyframe.renders.find((render) => render.chosen)?.sent ?? null)

type StagedMember = PanelKeyframe['staging']['cast'][number]

/** The fields of one of the cast in words, leaving out the empty ones. */
const memberFields = (member: StagedMember) => {
  const choice: Record<string, string> = {
    left: $t('Left'),
    centre: $t('Centre'),
    right: $t('Right'),
    both: $t('Both'),
  }
  const facing: Record<string, string> = {
    'camera': $t('The camera'),
    'camera-left': $t('Three-quarters toward the camera, left'),
    'camera-right': $t('Three-quarters toward the camera, right'),
    'left': $t('Left, side-on'),
    'right': $t('Right, side-on'),
    'away-left': $t('Three-quarters away, left'),
    'away-right': $t('Three-quarters away, right'),
    'away': $t('Away from the camera'),
  }

  return [
    { label: $t('Stands'), value: choice[member.stands] ?? member.stands },
    { label: $t('Faces'), value: facing[member.faces] ?? member.faces },
    { label: $t('Looks at'), value: member.looks_at },
    {
      label: $t('Holds'),
      value: member.holds ? `${member.holds}${member.hand ? ` (${choice[member.hand] ?? member.hand})` : ''}` : '',
    },
    { label: $t('Doing'), value: member.doing },
  ].filter((field) => field.value)
}

/** The one of the cast whose fields are shown; null for an object or no one selected. */
const editingMember = computed(() =>
  editable.value && form.cast.some((member) => member.name === props.selectedMember) ? props.selectedMember : null,
)

/** Asking whether the keyframes after this one should follow the change. */
const asking = ref(false)

/* A change made elsewhere, such as in the chat, restages the keyframe; follow it while the director has not edited anything. */
watch(
  () => props.keyframe.staging,
  () => {
    if (form.isDirty) return

    form.defaults({ ...fieldsOf(props.keyframe), redraw: false, following: false })
    form.reset()
  },
)

const send = (following: boolean) => {
  if (!props.keyframe.updateUrl) return

  asking.value = false
  form
    .transform((data) => ({ ...data, following }))
    .post(props.keyframe.updateUrl, {
      preserveScroll: true,
      onSuccess: () => {
        form.defaults()
        form.reset()
      },
    })
}

const redraw = () => {
  if (props.following > 0) {
    asking.value = true

    return
  }

  send(false)
}
</script>
