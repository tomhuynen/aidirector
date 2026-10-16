<template>
  <!-- The conversation about the shot: planned step by step, then about the drawn images, with what is selected. -->
  <div class="flex min-h-0 flex-1 flex-col gap-3">
    <p v-if="target !== undefined" class="shrink-0 text-xs font-medium tracking-wide text-muted-foreground uppercase">
      {{ target?.label ?? $t('The images are being drawn') }}
    </p>
    <ol v-else class="flex shrink-0 flex-wrap items-center gap-x-1.5 gap-y-1 text-xs" :aria-label="$t('Steps')">
      <li v-for="(step, i) in steps" :key="step.value" class="flex items-center gap-1.5">
        <ChevronRight v-if="i > 0" class="size-3 text-muted-foreground/60" />
        <span
          :class="
            i === stepIndex
              ? 'rounded-full bg-signal-soft px-2 py-0.5 font-medium text-foreground'
              : i < stepIndex
                ? 'text-foreground/70'
                : 'text-muted-foreground/60'
          "
          :aria-current="i === stepIndex ? 'step' : undefined"
          >{{ step.label }}</span
        >
      </li>
    </ol>
    <Chat
      class="min-h-0 flex-1"
      :messages="messages"
      :busy="busy"
      :error="error"
      :disabled="!chatUrl"
      :user-initial="userInitial"
      :label="target !== undefined ? $t('Talk about the images') : $t('Talk about the plan')"
      :placeholder="
        target !== undefined
          ? $t('For example: she should hold the badge in her right hand')
          : turns.length === 0
            ? $t('Describe your idea, for example: a visitor waits at the barrier while the guard checks the permit')
            : $t('Talk about the plan, for example: the camera stays in front of her')
      "
      @send="(text: string) => send(text)"
    >
      <template #text="{ message }">
        <!-- Places and objects the plan needs that the cast and sets do not have yet. -->
        <div
          v-if="message.id === 'new-elements' && newElements?.length"
          class="flex max-w-[85%] flex-col gap-3 py-1 text-[15px] leading-relaxed"
        >
          <p>
            {{ $t('Shall I make these for the cast and sets, with a picture in the style of the project?') }}
          </p>
          <ul class="space-y-1 text-sm">
            <li v-for="element in newElements" :key="element.name">
              <span class="font-medium">{{ element.name }}</span>
              <span class="text-muted-foreground"> · {{ typeLabel(element.type) }}</span>
              <span class="block text-muted-foreground">{{ element.description }}</span>
            </li>
          </ul>
          <div class="flex gap-2">
            <Button type="button" size="sm" :disabled="adding.processing || busy" @click="addElements">
              <LoaderCircle v-if="adding.processing" class="size-3.5 animate-spin" />
              {{ $t('Make them') }}
            </Button>
            <Button type="button" size="sm" variant="ghost" :disabled="adding.processing || busy" @click="leaveOut">
              {{ $t('Leave out') }}
            </Button>
          </div>
        </div>
        <!-- What the check found in the selected keyframe, with fixing or dismissing it below. -->
        <div
          v-else-if="message.id === 'issues' && issues"
          class="flex max-w-[85%] flex-col gap-3 py-1 text-[15px] leading-relaxed text-foreground"
        >
          <p>{{ $t('The check found something in this keyframe:') }}</p>
          <ul class="space-y-1.5">
            <li v-for="(issue, i) in issues.issues" :key="i" class="flex gap-2">
              <TriangleAlert class="mt-1 size-4 shrink-0 text-amber-500" />
              {{ issue }}
            </li>
          </ul>
          <div class="flex gap-2">
            <Button
              v-if="issues.fixUrl"
              type="button"
              size="sm"
              :disabled="resolving.processing"
              @click="resolve(issues.fixUrl)"
            >
              <Wand2 class="size-3.5" />
              {{ $t('Fix') }}
            </Button>
            <Button
              v-if="issues.redrawUrl"
              type="button"
              size="sm"
              variant="outline"
              :disabled="resolving.processing"
              @click="resolve(issues.redrawUrl)"
            >
              <RefreshCw class="size-3.5" />
              {{ $t('Draw again') }}
            </Button>
            <Button
              type="button"
              size="sm"
              variant="ghost"
              :disabled="resolving.processing"
              @click="resolve(issues.dismissUrl)"
            >
              {{ $t('Dismiss') }}
            </Button>
          </div>
        </div>
        <div
          v-else
          class="flex max-w-[85%] flex-col gap-3 text-[15px] leading-relaxed text-foreground"
          :class="message.role === 'user' ? 'rounded-2xl rounded-tr-md bg-signal-soft/70 px-4 py-3' : 'py-1'"
        >
          <p v-if="turnOf(message)?.about" class="text-xs text-muted-foreground">{{ turnOf(message)?.about }}</p>
          <p class="whitespace-pre-wrap">{{ message.content }}</p>

          <!-- Changes to the drawn images, made one keyframe after the other. -->
          <ul v-if="turnOf(message)?.changes?.length" class="space-y-1 text-sm text-muted-foreground">
            <li v-for="(change, c) in turnOf(message)?.changes ?? []" :key="c" class="flex items-start gap-1.5">
              <Wand2 class="mt-0.5 size-3.5 shrink-0 text-signal" />
              {{ change }}
            </li>
          </ul>

          <!-- The cast and sets made or redrawn here and not offered below: drawn while the conversation goes on, then shown with their picture. -->
          <ul v-if="drawnHere(message).length" class="grid grid-cols-3 gap-2">
            <li
              v-for="id in drawnHere(message)"
              :key="id"
              class="flex flex-col gap-1 rounded-lg border border-border bg-card p-1.5"
            >
              <!-- While it is drawn, the old picture fades behind a loader; the new one replaces it when ready. -->
              <span class="relative block aspect-square w-full overflow-hidden rounded-md bg-muted">
                <img
                  v-if="elementOf(id)?.imageUrl"
                  :src="elementOf(id)?.imageUrl ?? undefined"
                  :alt="elementOf(id)?.name"
                  :class="cn('size-full object-cover transition-opacity', elementOf(id)?.rendering && 'opacity-30')"
                />
                <span v-if="elementOf(id)?.rendering" class="absolute inset-0 flex items-center justify-center">
                  <LoaderCircle class="size-6 animate-spin text-signal" />
                </span>
              </span>
              <span class="truncate text-xs font-medium text-foreground">
                {{ elementOf(id)?.name }}
                <span v-if="elementOf(id)?.rendering" class="text-muted-foreground"> · {{ $t('drawing') }}</span>
              </span>
            </li>
          </ul>

          <!-- Three ways to tell the idea: pick one to go on with. -->
          <ul v-if="turnOf(message)?.storylines?.length" class="space-y-2">
            <li
              v-for="(option, s) in turnOf(message)?.storylines ?? []"
              :key="s"
              :class="
                cn(
                  'space-y-2 rounded-lg border border-border bg-card p-3',
                  pickedStoryline(message) === option.title && 'border-signal ring-2 ring-signal/40',
                )
              "
            >
              <p class="flex items-baseline justify-between gap-3">
                <span class="font-medium">{{ option.title }}</span>
                <span class="shrink-0 text-xs text-muted-foreground">{{ kindLabel(option.kind) }}</span>
              </p>
              <p class="text-sm text-muted-foreground">{{ option.storyline }}</p>
              <p v-if="option.needs" class="text-xs text-muted-foreground">
                {{ $t('Needs :what, to make at the cast step.', { what: option.needs }) }}
              </p>
              <Button
                v-if="isLast(message)"
                type="button"
                size="sm"
                variant="outline"
                :disabled="busy"
                @click="useStoryline(option)"
              >
                <Check class="size-3.5" />
                {{ $t('Use this') }}
              </Button>
            </li>
          </ul>

          <!-- The places that fit, or every place of the cast and sets: the director chooses one. -->
          <ul v-if="turnOf(message)?.pickPlace" class="grid grid-cols-3 gap-2">
            <li v-for="place in placesFor(message)" :key="place.id">
              <button
                type="button"
                :disabled="busy || !isLast(message)"
                :class="
                  cn(
                    'flex w-full flex-col gap-1 rounded-lg border border-border bg-card p-1.5 text-left transition-colors enabled:hover:border-muted-foreground/60',
                    pickedPlace(message) === place.id && 'border-signal ring-2 ring-signal/40',
                  )
                "
                @click="send(placePick(place.name))"
              >
                <img
                  v-if="place.imageUrl"
                  :src="place.imageUrl"
                  :alt="place.name"
                  class="aspect-square w-full rounded-md object-cover"
                />
                <span v-else class="flex aspect-square w-full items-center justify-center rounded-md bg-muted">
                  <LoaderCircle v-if="place.rendering" class="size-5 animate-spin text-muted-foreground" />
                </span>
                <span class="truncate text-xs font-medium">{{ place.name }}</span>
              </button>
            </li>
          </ul>

          <!-- The director agrees with the step in one click, or types what to change. -->
          <Button
            v-if="
              turnOf(message)?.confirm && isLast(message) && !turnOf(message)?.voiceOver && !turnOf(message)?.proposed
            "
            type="button"
            size="sm"
            variant="outline"
            class="self-start"
            :disabled="busy"
            @click="send($t('Yes, go on.'))"
          >
            <Check class="size-3.5" />
            {{ $t('Yes, go on') }}
          </Button>

          <!-- The cast and sets it suggests: tick the ones to use. -->
          <div v-if="turnOf(message)?.cast?.length" class="space-y-2">
            <ul class="grid grid-cols-3 gap-2">
              <li v-for="id in turnOf(message)?.cast ?? []" :key="id">
                <label
                  class="relative flex cursor-pointer flex-col gap-1 rounded-lg border border-border bg-card p-1.5 has-checked:border-signal has-checked:ring-2 has-checked:ring-signal/40 has-disabled:cursor-default"
                >
                  <input
                    v-model="picked[message.id]"
                    type="checkbox"
                    class="sr-only"
                    :value="id"
                    :disabled="!isLast(message)"
                  />
                  <!-- While it is drawn, the old picture fades behind a loader; the new one replaces it when ready. -->
                  <span class="relative block aspect-square w-full overflow-hidden rounded-md bg-muted">
                    <img
                      v-if="elementOf(id)?.imageUrl"
                      :src="elementOf(id)?.imageUrl ?? undefined"
                      :alt="elementOf(id)?.name"
                      :class="cn('size-full object-cover transition-opacity', elementOf(id)?.rendering && 'opacity-30')"
                    />
                    <span v-if="elementOf(id)?.rendering" class="absolute inset-0 flex items-center justify-center">
                      <LoaderCircle class="size-5 animate-spin text-signal" />
                    </span>
                  </span>
                  <span class="truncate text-xs font-medium">
                    {{ elementOf(id)?.name }}
                    <span v-if="elementOf(id)?.rendering" class="text-muted-foreground"> · {{ $t('drawing') }}</span>
                  </span>
                </label>
              </li>
            </ul>
            <Button
              v-if="isLast(message)"
              type="button"
              size="sm"
              :disabled="busy || (picked[message.id] ?? []).length === 0"
              @click="useCast(message.id)"
            >
              <Check class="size-3.5" />
              {{ $t('Use these') }}
            </Button>
          </div>

          <!-- A new voice-over, used once the director accepts it. -->
          <div v-if="turnOf(message)?.voiceOver" class="space-y-2">
            <p class="rounded-lg border border-border bg-card px-4 py-3 italic">
              “{{ turnOf(message)?.voiceOver?.text }}”
            </p>
            <p v-if="turnOf(message)?.voiceOver?.seconds" class="text-sm text-muted-foreground">
              {{
                $t('The shot becomes :seconds s long to speak it.', {
                  seconds: String(turnOf(message)?.voiceOver?.seconds),
                })
              }}
            </p>
            <div v-if="!turnOf(message)?.voiceOver?.state" class="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="answeringVoiceOver || !voiceOverUrl"
                @click="answerVoiceOver(turnIndex(message), false)"
              >
                <X class="size-3.5" />
                {{ $t('Decline') }}
              </Button>
              <Button
                type="button"
                size="sm"
                :disabled="answeringVoiceOver || !voiceOverUrl"
                @click="answerVoiceOver(turnIndex(message), true)"
              >
                <Check class="size-3.5" />
                {{ $t('Accept') }}
              </Button>
            </div>
            <p v-else class="flex items-center gap-1.5 text-sm text-muted-foreground">
              <component
                :is="turnOf(message)?.voiceOver?.state === 'accepted' ? Check : X"
                class="size-3.5 text-signal"
              />
              {{
                turnOf(message)?.voiceOver?.state === 'accepted'
                  ? $t('Used as the voice-over')
                  : $t('Kept the voice-over as it was')
              }}
            </p>
          </div>

          <!-- A change to one of the cast: the fields it changes, made once the director confirms it. -->
          <dl
            v-if="turnOf(message)?.proposed?.fields?.length"
            class="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-1 rounded-lg border border-border px-3 py-2 text-sm"
          >
            <template v-for="change in turnOf(message)?.proposed?.fields" :key="change.field">
              <dt class="text-muted-foreground">{{ fieldLabel(change.field) }}</dt>
              <dd>
                <span v-if="change.from" class="text-muted-foreground line-through">{{ fieldValue(change.from) }}</span>
                {{ change.from ? '→' : '' }} {{ fieldValue(change.to) || '—' }}
              </dd>
            </template>
          </dl>
          <div v-if="turnOf(message)?.proposed" class="flex flex-wrap items-center gap-2">
            <template v-if="!turnOf(message)?.proposed?.state">
              <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="answering || !changeUrl"
                @click="answer(turnIndex(message), false)"
              >
                <X class="size-3.5" />
                {{ $t('Cancel') }}
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="answering || !changeUrl"
                @click="confirm(turnIndex(message))"
              >
                <Check class="size-3.5" />
                {{ $t('Confirm') }}
              </Button>
            </template>
            <p v-else class="flex items-center gap-1.5 text-sm text-muted-foreground">
              <component :is="turnOf(message)?.proposed?.state === 'made' ? Check : X" class="size-3.5 text-signal" />
              {{ turnOf(message)?.proposed?.state === 'made' ? $t('Changed') : $t('Left as it is') }}
            </p>
          </div>

          <!-- Agreed in the chat: written into the shot and drawn. -->
          <p v-if="turnOf(message)?.proposal" class="flex items-center gap-1.5 text-sm text-muted-foreground">
            <Check class="size-3.5 text-signal" />
            {{ $t('Written into the plan') }} · {{ kindLabel(turnOf(message)?.proposal?.kind ?? '') }}
            <template v-if="turnOf(message)?.proposal?.settingFrom">
              · {{ $t('setting from :shot', { shot: turnOf(message)?.proposal?.settingFrom ?? '' }) }}
            </template>
          </p>
        </div>
      </template>
    </Chat>
    <FollowingDialog v-model:open="asking" :count="askingCount" :processing="answering" @answer="applyConfirmed" />
  </div>
</template>
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { usePage } from '@public/ts/composables/page'
import { $t } from '@public/ts/shared/i18n'
import { postJson } from '@public/ts/shared/postJson'
import Chat from '@public:components/chat/Chat.vue'
import type { ChatMessage } from '@public:components/chat/types'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Check, ChevronRight, LoaderCircle, RefreshCw, TriangleAlert, Wand2, X } from 'lucide-vue-next'
import { computed, reactive, ref, watch } from 'vue'

import FollowingDialog from './FollowingDialog.vue'
import type { PanelIssueGroup } from './KeyframesPanel.vue'

/** What the chat shows of a plan it wrote into the shot. */
export type PlanProposal = { kind: string; settingFrom?: string | null }

export type PlanChatTurn = {
  role: string
  text: string
  stage?: string
  made?: string[]
  adjusted?: string[]
  cast?: string[]
  proposal?: PlanProposal
  /** Once the images are drawn: what the director had selected. */
  about?: string
  /** The changes made to the drawn images. */
  changes?: string[]
  /** A change to one of the cast in a keyframe, made once the director confirms it, or left. */
  proposed?: {
    keyframe: string
    name: string
    change: string
    fields?: { field: string; from: string; to: string }[]
    state?: string
  }
  /** Three ways to tell the director's idea, to pick one from. */
  storylines?: StorylineOption[]
  /** The reply asks the director to agree with the step, with one button. */
  confirm?: boolean
  /** The director chooses the place from every place of the cast and sets. */
  pickPlace?: boolean
  /** The places to choose from, such as every corridor; every place when empty. */
  places?: string[]
  /** A new voice-over, used once the director accepts it, or declined. */
  voiceOver?: { text: string; seconds?: number; state?: string }
}

export type StorylineOption = { title: string; kind: string; storyline: string; needs?: string }

/** What the director has selected among the drawn images: a place to choose, an option for keyframe 1, or a keyframe. */
export type ChatTarget =
  | { kind: 'option'; option: number; label: string }
  | { kind: 'keyframe'; keyframe: string; member?: string; label: string }
  | null

const props = defineProps<{
  chatUrl?: string
  /** Once the images are drawn: what is selected, sent along so the director sees it; null while nothing is drawn yet. */
  target?: ChatTarget
  /** The conversation so far, as stored with the shot. */
  conversation?: PlanChatTurn[]
  kinds: { value: string; label: string; description: string }[]
  elements: { id: string; type: string; name: string; imageUrl: string | null; rendering?: boolean }[]
  /** What the keyframes wait for before they are drawn, such as "the place of SH100". */
  waitingFor?: string | null
  /** The ids of the cast and sets made from this chat; a picture still missing is being drawn. */
  /** Places and objects the plan needs that the cast and sets do not have yet. */
  newElements?: { name: string; type: string; description: string }[]
  elementsUrl?: string
  /** Makes or leaves a change proposed for one of the cast. */
  changeUrl?: string
  /** Uses or declines a suggested voice-over. */
  voiceOverUrl?: string
  /** Per keyframe id, how many drawn keyframes come after it; they may be drawn again to follow a change. */
  following?: Record<string, number>
  /** What the check found in the selected keyframe, shown as the latest message. */
  issues?: PanelIssueGroup | null
}>()

const turns = ref<PlanChatTurn[]>([...(props.conversation ?? [])])

const { account } = usePage()
const userInitial = computed(() => account.value?.name.trim().charAt(0).toUpperCase() ?? '')

const busy = ref(false)
const error = ref<string | null>(null)
/** The cast and sets ticked per message, starting with all it suggested. */
const picked = reactive<Record<string, string[]>>({})

const adding = useForm<{ elements?: string }>({})

/**
 * Made for the cast and sets: they join the cast offered last, ticked, and
 * show there while their picture is drawn. Without a cast offered, the
 * conversation goes on from the message that they were added.
 */
const addElements = () => {
  const names = (props.newElements ?? []).map((element) => element.name)

  if (!props.elementsUrl) return

  adding.post(props.elementsUrl, {
    preserveScroll: true,
    onSuccess: () => {
      const made = props.elements.filter((element) => names.includes(element.name)).map((element) => element.id)
      const offered = turns.value.findLastIndex((turn) => turn.role === 'assistant' && (turn.cast?.length ?? 0) > 0)

      if (offered < 0) {
        void send($t('I added :names to the cast and sets.', { names: names.join(', ') }), { made })

        return
      }

      const turn = turns.value[offered]
      turns.value[offered] = { ...turn, cast: [...new Set([...(turn.cast ?? []), ...made])] }
      picked[`turn-${offered}`] = [...new Set([...(picked[`turn-${offered}`] ?? []), ...made])]
    },
  })
}
const leaveOut = () =>
  props.elementsUrl &&
  adding.delete(props.elementsUrl, {
    preserveScroll: true,
    onSuccess: () => send($t('Leave those out.')),
  })

/** The steps the shot is built up in; the plan director says which one it is at. */
const steps = [
  { value: 'idea', label: $t('Idea') },
  { value: 'storyline', label: $t('Storyline') },
  { value: 'keyframes', label: $t('Keyframes') },
  { value: 'cast', label: $t('Cast') },
  { value: 'plan', label: $t('Plan') },
]

const stepIndex = computed(() => {
  const stage = [...turns.value].reverse().find((turn) => turn.stage)?.stage

  return Math.max(
    0,
    steps.findIndex((step) => step.value === (stage ?? 'idea')),
  )
})

const kindLabel = (value: string) => props.kinds.find((option) => option.value === value)?.label ?? value
const typeLabel = (type: string) =>
  ({ person: $t('Person'), place: $t('Place') })[type as 'person' | 'place'] ?? $t('Object')
const elementOf = (id: string) => props.elements.find((element) => element.id === id)

const messages = computed<ChatMessage[]>(() => [
  ...turns.value.flatMap((turn, i) => [
    ...questionsAt(i),
    {
      id: `turn-${i}`,
      role: turn.role === 'director' ? ('user' as const) : ('assistant' as const),
      kind: 'text' as const,
      content: turn.text,
    },
  ]),
  ...questionsAt(turns.value.length),
  ...(props.issues ? [{ id: 'issues', role: 'assistant' as const, kind: 'text' as const, content: '' }] : []),
  ...(props.waitingFor
    ? [
        {
          id: 'waiting',
          role: 'assistant' as const,
          kind: 'text' as const,
          content: $t('The keyframes are drawn as soon as :what is ready.', { what: props.waitingFor }),
        },
      ]
    : []),
  // Offered to make for the cast and sets: it waits below the conversation until added or left out.
  ...(props.newElements?.length
    ? [{ id: 'new-elements', role: 'assistant' as const, kind: 'text' as const, content: '' }]
    : []),
])

/** Asked when the director selects one of the cast, at the point in the conversation where they did. */
const questions = ref<{ at: number; text: string }[]>([])

const questionsAt = (at: number) =>
  questions.value
    .map((question, i) => ({ ...question, i }))
    .filter((question) => question.at === at)
    .map((question) => ({
      id: `question-${question.i}`,
      role: 'assistant' as const,
      kind: 'text' as const,
      content: question.text,
    }))

watch(
  () => (props.target?.kind === 'keyframe' ? props.target.member : undefined),
  (member) => {
    if (!member) return

    const text = $t('What do you want to change about :name?', { name: member })

    if (questions.value.at(-1)?.text !== text || questions.value.at(-1)?.at !== turns.value.length)
      questions.value = [...questions.value, { at: turns.value.length, text }]
  },
  { immediate: true },
)

const turnIndex = (message: ChatMessage) => Number(message.id.slice(5))

const turnOf = (message: ChatMessage): PlanChatTurn | undefined =>
  message.id.startsWith('turn-') ? turns.value[turnIndex(message)] : undefined

/** Fixing changes only where something is wrong, drawing again redraws the whole keyframe, dismissing only takes the issues off the list. */
const resolving = useForm({})
const resolve = (url: string) => resolving.post(url, { preserveScroll: true })

const answeringVoiceOver = ref(false)

/** Uses the suggested voice-over, or keeps the one there is. */
const answerVoiceOver = async (turn: number, accept: boolean) => {
  if (!props.voiceOverUrl || answeringVoiceOver.value) return

  answeringVoiceOver.value = true
  error.value = null

  try {
    const { messages: saved } = await postJson<{ messages: PlanChatTurn[] }>(props.voiceOverUrl, { turn, accept })
    turns.value = saved

    if (accept) router.reload({ only: ['shot'] })
  } catch (exception) {
    error.value =
      exception instanceof Error ? exception.message : $t('The voice-over could not be saved. Please try again.')
  } finally {
    answeringVoiceOver.value = false
  }
}

const answering = ref(false)
/** Asking whether the keyframes after the changed one follow the change. */
const asking = ref(false)
const askingTurn = ref<number | null>(null)
const askingCount = computed(() => {
  const keyframe = askingTurn.value === null ? undefined : turns.value[askingTurn.value]?.proposed?.keyframe

  return keyframe ? (props.following?.[keyframe] ?? 0) : 0
})

const confirm = (turn: number) => {
  askingTurn.value = turn

  if (askingCount.value > 0) {
    asking.value = true

    return
  }

  void answer(turn, true)
}

const applyConfirmed = (following: boolean) => {
  asking.value = false

  if (askingTurn.value !== null) void answer(askingTurn.value, true, following)
}

/** The names of the staging fields a proposed change sets. */
const fieldLabel = (field: string) =>
  ({
    stands: $t('Stands'),
    faces: $t('Faces'),
    looks_at: $t('Looks at'),
    holds: $t('Holds'),
    hand: $t('Hand'),
    doing: $t('Doing'),
  })[field] ?? field

/** A field's value in words; where someone stands or faces is a fixed choice. */
const fieldValue = (value: string) =>
  ({
    camera: $t('the camera'),
    away: $t('away from the camera'),
    left: $t('left'),
    right: $t('right'),
    centre: $t('centre'),
    both: $t('both'),
  })[value] ?? value

/** Makes the proposed change, or leaves it; the keyframe is drawn again when it is made. */
const answer = async (turn: number, apply: boolean, following = false) => {
  if (!props.changeUrl || answering.value) return

  answering.value = true
  error.value = null

  try {
    const { messages: saved } = await postJson<{ messages: PlanChatTurn[] }>(props.changeUrl, {
      turn,
      apply,
      following,
    })
    turns.value = saved

    if (apply) router.reload({ only: ['shot', 'keyframes'] })
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : $t('The change could not be made. Please try again.')
  } finally {
    answering.value = false
  }
}

/** Made or redrawn from this message; what it also offers to tick shows only there. */
const drawnHere = (message: ChatMessage) =>
  [...(turnOf(message)?.made ?? []), ...(turnOf(message)?.adjusted ?? [])].filter(
    (id) => !(turnOf(message)?.cast ?? []).includes(id),
  )

const send = async (text: string, extra: { made?: string[] } = {}) => {
  const message = text.trim()

  if (message === '' || busy.value || !props.chatUrl) return

  busy.value = true
  error.value = null
  turns.value = [...turns.value, { role: 'director', text: message, about: props.target?.label, ...extra }]

  const target = props.target
  const selection = !target
    ? {}
    : target.kind === 'keyframe'
      ? { target: 'keyframe', keyframe: target.keyframe, member: target.member ?? null }
      : { target: target.kind, option: target.option }

  try {
    const { messages: saved, reload } = await postJson<{ messages: PlanChatTurn[]; reload: boolean }>(props.chatUrl, {
      message,
      ...extra,
      ...selection,
    })
    turns.value = saved

    // The reply changed the shot: a plan written and drawn, cast and sets offered or redrawn, or shots added.
    if (reload) router.reload({ only: ['shot', 'keyframes', 'siblings', 'elements'] })

    saved.forEach((turn, i) => {
      if (turn.cast?.length && picked[`turn-${i}`] === undefined) picked[`turn-${i}`] = [...turn.cast]
    })
  } catch {
    turns.value = turns.value.slice(0, -1)
    error.value = $t('The plan director could not answer. Please try again.')
  } finally {
    busy.value = false
  }
}

const storylinePick = (title: string) => $t('Use “:title”.', { title })

const useStoryline = (option: StorylineOption) => void send(storylinePick(option.title))

/** The title of the storyline the director picked from this message's suggestions, by the message right after it. */
const pickedStoryline = (message: ChatMessage) => {
  const next = turns.value[turnIndex(message) + 1]

  return turnOf(message)?.storylines?.find((option) => next?.text === storylinePick(option.title))?.title ?? null
}

/** Only the latest message can still be answered with its buttons. */
const isLast = (message: ChatMessage) => message.id.startsWith('turn-') && turnIndex(message) === turns.value.length - 1

/** Every place of the cast and sets, to choose the shot's place from. */
const places = computed(() => props.elements.filter((element) => element.type === 'place'))

/** The places a message offers: the ones it names, or every place. */
const placesFor = (message: ChatMessage) => {
  const named = turnOf(message)?.places ?? []

  return named.length > 0 ? places.value.filter((place) => named.includes(place.id)) : places.value
}

const placePick = (name: string) => $t('Use the place “:name”.', { name })

/** The place the director chose from this message, by the message right after it. */
const pickedPlace = (message: ChatMessage) => {
  const next = turns.value[turnIndex(message) + 1]

  return places.value.find((place) => next?.text === placePick(place.name))?.id ?? null
}

const useCast = (id: string) => {
  const names = (picked[id] ?? []).map((element) => elementOf(element)?.name).filter(Boolean)
  void send($t('Use these: :names.', { names: names.join(', ') }))
}

// Suggestions from before a refresh start ticked as well.
turns.value.forEach((turn, i) => {
  if (turn.cast?.length) picked[`turn-${i}`] = [...turn.cast]
})
</script>
