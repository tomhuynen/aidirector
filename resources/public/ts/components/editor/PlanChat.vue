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
            <Button type="button" size="sm" :disabled="adding.processing" @click="addElements">
              <LoaderCircle v-if="adding.processing" class="size-3.5 animate-spin" />
              {{ $t('Make them') }}
            </Button>
            <Button type="button" size="sm" variant="ghost" :disabled="adding.processing" @click="leaveOut">
              {{ $t('Leave out') }}
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

          <!-- The cast and sets made or redrawn here: drawn while the conversation goes on, then shown with their picture. -->
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

          <!-- The cast and sets it suggests: tick the ones to use. -->
          <div v-if="turnOf(message)?.cast?.length" class="space-y-2">
            <ul class="grid grid-cols-3 gap-2">
              <li v-for="id in turnOf(message)?.cast ?? []" :key="id">
                <label
                  class="relative flex cursor-pointer flex-col gap-1 rounded-lg border border-border bg-card p-1.5 has-checked:border-signal has-checked:ring-2 has-checked:ring-signal/40"
                >
                  <input v-model="picked[message.id]" type="checkbox" class="sr-only" :value="id" />
                  <img
                    v-if="elementOf(id)?.imageUrl"
                    :src="elementOf(id)?.imageUrl ?? undefined"
                    :alt="elementOf(id)?.name"
                    class="aspect-square w-full rounded-md object-cover"
                  />
                  <span v-else class="flex aspect-square w-full items-center justify-center rounded-md bg-muted">
                    <LoaderCircle v-if="elementOf(id)?.rendering" class="size-5 animate-spin text-muted-foreground" />
                  </span>
                  <span class="truncate text-xs font-medium">{{ elementOf(id)?.name }}</span>
                </label>
              </li>
            </ul>
            <Button
              type="button"
              size="sm"
              :disabled="busy || (picked[message.id] ?? []).length === 0"
              @click="useCast(message.id)"
            >
              <Check class="size-3.5" />
              {{ $t('Use these') }}
            </Button>
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
import { Check, ChevronRight, LoaderCircle, Wand2 } from 'lucide-vue-next'
import { computed, reactive, ref } from 'vue'

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
}

/** What the director has selected among the drawn images: a place to choose, an option for keyframe 1, or a keyframe. */
export type ChatTarget =
  | { kind: 'place'; option: number; label: string }
  | { kind: 'option'; option: number; label: string }
  | { kind: 'keyframe'; keyframe: string; label: string }
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
}>()

const turns = ref<PlanChatTurn[]>([...(props.conversation ?? [])])

const { account } = usePage()
const userInitial = computed(() => account.value?.name.trim().charAt(0).toUpperCase() ?? '')

const busy = ref(false)
const error = ref<string | null>(null)
/** The cast and sets ticked per message, starting with all it suggested. */
const picked = reactive<Record<string, string[]>>({})

const adding = useForm<{ elements?: string }>({})
/** Added or left out, the conversation goes on from there. */
const addElements = () => {
  const names = (props.newElements ?? []).map((element) => element.name).join(', ')

  if (props.elementsUrl)
    adding.post(props.elementsUrl, {
      preserveScroll: true,
      // The new ones go with the message, so they stay in the conversation.
      onSuccess: () =>
        send($t('I added :names to the cast and sets.', { names }), {
          made: props.elements
            .filter((element) => names.split(', ').includes(element.name))
            .map((element) => element.id),
        }),
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
  { value: 'takeaway', label: $t('Takeaway') },
  { value: 'idea', label: $t('Idea') },
  { value: 'kind', label: $t('Kind') },
  { value: 'keyframes', label: $t('Keyframes') },
  { value: 'cast', label: $t('Cast') },
  { value: 'plan', label: $t('Plan') },
]

const stepIndex = computed(() => {
  const stage = [...turns.value].reverse().find((turn) => turn.stage)?.stage

  return Math.max(
    0,
    steps.findIndex((step) => step.value === (stage ?? 'takeaway')),
  )
})

const kindLabel = (value: string) => props.kinds.find((option) => option.value === value)?.label ?? value
const typeLabel = (type: string) =>
  ({ person: $t('Person'), place: $t('Place') })[type as 'person' | 'place'] ?? $t('Object')
const elementOf = (id: string) => props.elements.find((element) => element.id === id)

const messages = computed<ChatMessage[]>(() => [
  // A shot planned from scratch opens with the first question; a draft from the project setup opens with the draft itself.
  ...(turns.value[0]?.role === 'assistant'
    ? []
    : [
        {
          id: 'greeting',
          role: 'assistant' as const,
          kind: 'text' as const,
          content: $t('What should the viewer learn from this shot?'),
        },
      ]),
  ...turns.value.map((turn, i) => ({
    id: `turn-${i}`,
    role: turn.role === 'director' ? ('user' as const) : ('assistant' as const),
    kind: 'text' as const,
    content: turn.text,
  })),
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

const turnOf = (message: ChatMessage): PlanChatTurn | undefined =>
  message.id.startsWith('turn-') ? turns.value[Number(message.id.slice(5))] : undefined

const drawnHere = (message: ChatMessage) => [...(turnOf(message)?.made ?? []), ...(turnOf(message)?.adjusted ?? [])]

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
      ? { target: 'keyframe', keyframe: target.keyframe }
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

const useCast = (id: string) => {
  const names = (picked[id] ?? []).map((element) => elementOf(element)?.name).filter(Boolean)
  void send($t('Use these: :names.', { names: names.join(', ') }))
}

// Suggestions from before a refresh start ticked as well.
turns.value.forEach((turn, i) => {
  if (turn.cast?.length) picked[`turn-${i}`] = [...turn.cast]
})
</script>
