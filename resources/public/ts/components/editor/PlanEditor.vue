<template>
  <!-- The plan scrolls; the footer with the actions stays in view. -->
  <div class="flex min-h-0 min-w-0 flex-1 flex-col">
    <div class="min-h-0 flex-1 overflow-y-auto p-6">
      <div class="flex w-full max-w-3xl flex-col gap-6">
        <header class="space-y-1">
          <h2 class="text-2xl font-semibold">{{ $t('The plan') }}</h2>
          <p class="text-muted-foreground">
            {{
              keyframes.length === 0 && form.keyframes.length === 0
                ? $t('Write what happens and describe each keyframe. Nothing is drawn until you say so.')
                : $t(
                    'Check the draft and change what you like. What the image model gets is sent exactly as it stands here.',
                  )
            }}
          </p>
        </header>

        <!-- The planner found two messages in the takeaway: one shot each reads better. -->
        <section
          v-if="split"
          class="space-y-3 rounded-xl border border-signal/40 bg-signal-soft/30 px-4 py-4 text-sm leading-relaxed"
          aria-labelledby="plan-split-heading"
        >
          <p id="plan-split-heading" class="font-semibold">
            {{ $t('This shot tells two things. Split it into two shots?') }}
          </p>
          <ol class="space-y-1.5">
            <li v-for="(part, i) in split.parts" :key="i" class="flex gap-2">
              <span class="text-muted-foreground tabular-nums">{{ i + 1 }}.</span>
              <span>
                {{ part.takeaway }}
                <span class="text-muted-foreground"> · {{ kindLabel(part.kind) }}</span>
              </span>
            </li>
          </ol>
          <p class="text-muted-foreground">
            {{
              $t('This shot keeps the first, a new shot right after it gets the second, and both are planned again.')
            }}
          </p>
          <InputError :message="splitting.errors.split" />
          <div class="flex gap-2">
            <Button type="button" size="sm" :disabled="splitting.processing" @click="splitShot">
              <LoaderCircle v-if="splitting.processing" class="size-4 animate-spin" />
              {{ $t('Split') }}
            </Button>
            <Button type="button" size="sm" variant="ghost" :disabled="splitting.processing" @click="keepAsOne">
              {{ $t('Keep as one') }}
            </Button>
          </div>
        </section>

        <div class="space-y-1.5">
          <Label for="plan-storyline">{{ $t('Storyline') }}</Label>
          <Textarea
            id="plan-storyline"
            v-model="form.storyline"
            rows="3"
            maxlength="2000"
            :placeholder="$t('What happens in this shot, from beginning to end.')"
            class="text-[15px] leading-relaxed"
          />
          <InputError :message="form.errors.storyline" />
        </div>

        <!-- Set from the chat; every drawing, adjustment and check follows them. -->
        <div v-if="form.rules.length > 0" class="space-y-2">
          <p class="text-sm font-medium">{{ $t('Rules for this shot') }}</p>
          <ul class="space-y-1.5">
            <li
              v-for="(rule, i) in form.rules"
              :key="rule"
              class="flex items-start gap-2 rounded-lg border border-border bg-card px-3.5 py-2 text-[15px] leading-relaxed"
            >
              <ShieldCheck class="mt-1 size-4 shrink-0 text-signal" />
              <span class="flex-1">{{ rule }}</span>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                class="size-7"
                :aria-label="$t('Remove this rule')"
                @click="form.rules.splice(i, 1)"
              >
                <X class="size-4" />
              </Button>
            </li>
          </ul>
          <p class="text-sm text-muted-foreground">
            {{
              $t(
                'Every keyframe is drawn, adjusted and checked by these rules. A removed rule is dropped when you save or send a message.',
              )
            }}
          </p>
        </div>

        <!-- The kind decides the framing; the spot, the light and the length follow from the plan and the voice-over. -->
        <div class="space-y-1.5">
          <Label for="plan-kind">{{ $t('Kind of shot') }}</Label>
          <!-- Another kind needs another plan: switching asks first, then the director rewrites it. -->
          <NativeSelect id="plan-kind" v-model="kindChoice" class="w-full" :disabled="switching.processing">
            <option v-for="option in kinds" :key="option.value" :value="option.value">{{ option.label }}</option>
          </NativeSelect>
          <p class="text-sm text-muted-foreground">
            {{ kinds.find((option) => option.value === form.kind)?.description }}
          </p>
        </div>

        <ol class="space-y-4">
          <li
            v-for="(keyframe, i) in form.keyframes"
            :key="keyframe.uid"
            class="space-y-3 rounded-xl border border-border bg-card/60 p-4"
            :aria-busy="keyframe.pending"
          >
            <!-- Being written from the chat: its place in the order shows, its content follows. -->
            <template v-if="keyframe.pending">
              <div class="flex items-center gap-3">
                <span
                  class="flex size-7 shrink-0 items-center justify-center rounded-full bg-signal text-sm font-semibold text-primary-foreground tabular-nums"
                  >{{ i + 1 }}</span
                >
                <Skeleton class="h-10 flex-1" />
              </div>
              <Skeleton class="h-20 w-full" />
              <Skeleton class="h-16 w-full" />
              <p class="flex items-center gap-2 text-xs text-signal">
                <LoaderCircle class="size-3.5 animate-spin" />
                {{ $t('Writing this keyframe…') }}
              </p>
            </template>
            <template v-else>
              <div class="flex items-center gap-3">
                <span
                  class="flex size-7 shrink-0 items-center justify-center rounded-full bg-signal text-sm font-semibold text-primary-foreground tabular-nums"
                  >{{ i + 1 }}</span
                >
                <Input
                  v-model="keyframe.title"
                  maxlength="120"
                  required
                  :aria-label="$t('Title of keyframe :n', { n: String(i + 1) })"
                  :placeholder="$t('Title, such as At the bin')"
                  class="font-semibold"
                />
                <div class="flex shrink-0 items-center">
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="$t('Move up')"
                    :disabled="i === 0"
                    @click="move(i, -1)"
                  >
                    <ArrowUp class="size-4" />
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    :aria-label="$t('Move down')"
                    :disabled="i === form.keyframes.length - 1"
                    @click="move(i, 1)"
                  >
                    <ArrowDown class="size-4" />
                  </Button>
                  <Button type="button" variant="ghost" size="icon-sm" :aria-label="$t('Remove')" @click="remove(i)">
                    <Trash2 class="size-4" />
                  </Button>
                </div>
              </div>
              <Label :for="`plan-description-${i}`" class="text-xs text-muted-foreground">
                {{ $t('Description') }}
              </Label>
              <Textarea
                :id="`plan-description-${i}`"
                v-model="keyframe.description"
                rows="3"
                maxlength="2000"
                required
                :aria-label="$t('Description of keyframe :n', { n: String(i + 1) })"
                :placeholder="
                  $t(
                    'Exactly what is visible: who stands where, which way they face from the camera (face or back to the camera), which hand holds what, the state of the objects.',
                  )
                "
                class="text-[15px] leading-relaxed"
              />
              <!-- Sent as the last sentence of the description; the check judges the image by both. -->
              <Label :for="`plan-spatial-${i}`" class="text-xs text-muted-foreground">{{ $t('Spatial fact') }}</Label>
              <Input
                :id="`plan-spatial-${i}`"
                v-model="keyframe.spatial"
                maxlength="500"
                :placeholder="
                  $t('The one thing a viewer must see at a glance, such as: both feet are behind the yellow line')
                "
              />
              <!-- Who and what is in the keyframe: their pictures go to the image model. -->
              <KeyframeCastPicker
                v-if="elements.length > 0"
                v-model="keyframe.elements"
                :elements="elements"
                :types="types"
              />
              <InputError :message="form.errors[`keyframes.${i}.title`] ?? form.errors[`keyframes.${i}.description`]" />
            </template>
          </li>
        </ol>

        <div>
          <Button type="button" variant="outline" :disabled="form.keyframes.length >= max" @click="add">
            <Plus class="size-4" />
            {{ $t('Add keyframe') }}
          </Button>
          <InputError :message="form.errors.keyframes" />
        </div>
      </div>
    </div>

    <footer class="shrink-0 space-y-3 border-t border-border px-6 py-4">
      <!-- Tell the director what to change; affected keyframes show as busy until they are written. -->
      <form class="flex w-full max-w-3xl flex-col gap-2" @submit.prevent="sendChat">
        <p
          v-if="chat.reply || chat.error"
          :class="cn('text-sm', chat.error ? 'text-destructive' : 'text-muted-foreground')"
        >
          {{ chat.error ?? chat.reply }}
        </p>
        <div class="flex gap-2">
          <Input
            v-model="chat.message"
            maxlength="2000"
            :disabled="chat.busy"
            :aria-label="$t('Ask the director to change the plan')"
            :placeholder="
              mode === 'manual'
                ? $t('For example: put a keyframe before 1 where she walks up to the door')
                : $t('For example: change keyframe 2 so she looks up at the crane, or add a keyframe before 1')
            "
          />
          <Button type="submit" variant="outline" :disabled="chat.busy || chat.message.trim() === ''">
            <LoaderCircle v-if="chat.busy" class="size-4 animate-spin" />
            <Send v-else class="size-4" />
            {{ $t('Send') }}
          </Button>
        </div>
      </form>
      <div class="flex w-full max-w-3xl items-center justify-end gap-3">
        <Button type="button" variant="outline" :disabled="form.processing || chat.busy" @click="save(false)">
          {{ $t('Save the plan') }}
        </Button>
        <Button
          type="button"
          :disabled="form.processing || chat.busy || form.keyframes.length === 0"
          @click="save(true)"
        >
          <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
          <Wand v-else class="size-4" />
          {{ $t('Save and draw the keyframes') }}
        </Button>
      </div>
    </footer>

    <Dialog v-model:open="switchOpen">
      <DialogContent>
        <DialogHeader class="space-y-3">
          <DialogTitle class="font-display text-2xl font-medium">
            {{ $t('Switch to :kind?', { kind: kindLabel(switchTo) }) }}
          </DialogTitle>
          <DialogDescription>
            {{
              $t(
                'The director rewrites the plan as a :kind, from the same brief and storyline. Changes to this plan that are not saved are lost.',
                { kind: kindLabel(switchTo).toLowerCase() },
              )
            }}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter class="gap-2">
          <DialogClose as-child>
            <Button type="button" variant="secondary">{{ $t('Cancel') }}</Button>
          </DialogClose>
          <Button type="button" :disabled="switching.processing" @click="confirmSwitch">
            <LoaderCircle v-if="switching.processing" class="size-4 animate-spin" />
            {{ $t('Rewrite the plan') }}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  </div>
</template>
<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { postJson } from '@public/ts/shared/postJson'
import InputError from '@public:components/Form/InputError.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@shared:ui/dialog'
import { Input } from '@shared:ui/input'
import { Label } from '@shared:ui/label'
import { NativeSelect } from '@shared:ui/native-select'
import { Skeleton } from '@shared:ui/skeleton'
import { Textarea } from '@shared:ui/textarea'
import { ArrowDown, ArrowUp, LoaderCircle, Plus, Send, ShieldCheck, Trash2, Wand, X } from 'lucide-vue-next'
import { reactive, ref, watch } from 'vue'

import KeyframeCastPicker from './KeyframeCastPicker.vue'

type PlanKeyframe = {
  uid: number
  title: string
  description: string
  spatial: string
  elements: string[]
  /** Being written from the chat. */
  pending?: boolean
}

const props = defineProps<{
  storyline: string
  keyframes: {
    title: string
    description: string
    spatial?: string | null
    elements?: string[]
  }[]
  /** The project's cast and sets to pick from. */
  elements: { id: string; type: string; name: string; imageUrl: string | null }[]
  /** The element categories, for the picker. */
  types: { value: string; label: string; plural: string }[]
  /** The cast and sets chosen in the brief, ticked by default where a keyframe has none. */
  preferred: string[]
  /** A scene at one place, or a montage of separate stills. */
  kind: string
  kinds: { value: string; label: string; description: string }[]
  /** Switches the kind and has the director rewrite the plan for it. */
  kindUrl: string
  /** The planner's proposal to split the shot in two, each with one message. */
  split?: { parts: { takeaway: string; kind: string }[] } | null
  /** Splits the shot as proposed; a DELETE keeps it as one. */
  splitUrl?: string
  /** The most keyframes the director may have in one shot. */
  max: number
  saveUrl: string
  drawUrl: string
  /** Works out which keyframes a chat message touches. */
  changesUrl: string
  /** Writes the keyframes a chat message asked for. */
  writeUrl: string
  /** Drafted by the planner (auto) or written by the director (manual). */
  mode: 'auto' | 'manual'
  /** What must always or never happen in this shot. */
  rules: string[]
}>()

let nextUid = 0

/** The ids of the named cast and sets; none named, the brief's choice. */
function idsFor(names: string[]): string[] {
  const ids = props.elements.filter((element) => names.includes(element.name)).map((element) => element.id)

  return ids.length > 0 ? ids : [...props.preferred]
}

const form = useForm<{
  storyline: string
  kind: string
  rules: string[]
  keyframes: PlanKeyframe[]
}>({
  storyline: props.storyline,
  kind: props.kind,
  rules: [...props.rules],
  keyframes: props.keyframes.map((keyframe) => ({
    uid: nextUid++,
    title: keyframe.title,
    description: keyframe.description,
    spatial: keyframe.spatial ?? '',
    elements: idsFor(keyframe.elements ?? []),
  })),
})

const splitting = useForm<{ split?: string }>({})
const splitShot = () => props.splitUrl && splitting.post(props.splitUrl, { preserveScroll: true })
const keepAsOne = () => props.splitUrl && splitting.delete(props.splitUrl, { preserveScroll: true })

const switchOpen = ref(false)
const switchTo = ref('')
const switching = useForm<{ kind: string }>({ kind: '' })

const kindLabel = (value: string) => props.kinds.find((option) => option.value === value)?.label ?? value

/** What the select shows; it only becomes the kind once the director confirms the rewrite. */
const kindChoice = ref(form.kind)

watch(kindChoice, (value) => {
  if (value === form.kind) return

  switchTo.value = value
  switchOpen.value = true
})

// Cancelling the switch puts the select back on the current kind.
watch(switchOpen, (open) => {
  if (!open && !switching.processing) kindChoice.value = form.kind
})

const confirmSwitch = () => {
  switching.kind = switchTo.value
  switching.post(props.kindUrl, { preserveScroll: true, onSuccess: () => (switchOpen.value = false) })
}

const add = () =>
  form.keyframes.push({
    uid: nextUid++,
    title: '',
    description: '',
    spatial: '',
    elements: [...props.preferred],
  })

const remove = (index: number) => form.keyframes.splice(index, 1)

const move = (index: number, step: number) => {
  const [keyframe] = form.keyframes.splice(index, 1)
  form.keyframes.splice(index + step, 0, keyframe)
}

/** Saves the plan; with `draw` the keyframes are drawn from it right after. */
const save = (draw: boolean) =>
  form
    .transform((data) => ({
      ...data,
      keyframes: data.keyframes.map(({ title, description, spatial, elements }) => ({
        title,
        description,
        spatial,
        elements,
      })),
    }))
    .post(props.saveUrl, {
      preserveScroll: true,
      onSuccess: () => {
        if (draw) router.post(props.drawUrl, {}, { preserveScroll: true })
      },
    })

type Change = { op: 'insert' | 'update' | 'remove' | 'move'; at: number; from: number; instruction: string }
type Written = Omit<PlanKeyframe, 'uid' | 'pending'> & { position: number }

const chat = reactive<{ message: string; busy: boolean; reply: string | null; error: string | null }>({
  message: '',
  busy: false,
  reply: null,
  error: null,
})

const outline = () => form.keyframes.map(({ title, description, spatial }) => ({ title, description, spatial }))

/**
 * Two steps: first the changes, applied straight away so the order is
 * right and the touched keyframes show as busy; then their content.
 */
const sendChat = async () => {
  const message = chat.message.trim()

  if (message === '' || chat.busy) return

  Object.assign(chat, { busy: true, error: null, reply: null })

  const targets = new Map<PlanKeyframe, string>()

  try {
    const { changes, reply, rules, storyline } = await postJson<{
      changes: Change[]
      reply: string
      rules: string[]
      storyline: string
    }>(props.changesUrl, {
      message,
      storyline: form.storyline,
      rules: form.rules,
      keyframes: outline(),
    })

    chat.reply = reply
    // A rule is kept on the shot straight away; the storyline follows it when it broke the rule.
    form.rules = rules

    if (storyline !== '') form.storyline = storyline

    for (const change of changes) {
      const at = Math.min(Math.max(change.at, 1), form.keyframes.length + (change.op === 'insert' ? 1 : 0)) - 1

      if (change.op === 'insert') {
        const keyframe: PlanKeyframe = {
          uid: nextUid++,
          title: '',
          description: '',
          spatial: '',
          elements: [...props.preferred],
          pending: true,
        }
        form.keyframes.splice(at, 0, keyframe)
        // The list keeps a reactive copy: remember that one, so the written content finds it again.
        targets.set(form.keyframes[at], change.instruction || message)
      } else if (change.op === 'update' && form.keyframes[at]) {
        form.keyframes[at].pending = true
        targets.set(form.keyframes[at], change.instruction || message)
      } else if (change.op === 'remove' && form.keyframes[at]) {
        targets.delete(form.keyframes[at])
        form.keyframes.splice(at, 1)
      } else if (change.op === 'move' && form.keyframes[change.from - 1]) {
        const [keyframe] = form.keyframes.splice(change.from - 1, 1)
        form.keyframes.splice(Math.min(at, form.keyframes.length), 0, keyframe)
      }
    }

    if (targets.size > 0) {
      const { keyframes } = await postJson<{ keyframes: Written[] }>(props.writeUrl, {
        message,
        storyline: form.storyline,
        kind: form.kind,
        keyframes: outline(),
        targets: [...targets].map(([keyframe, instruction]) => ({
          position: form.keyframes.indexOf(keyframe) + 1,
          instruction,
        })),
      })

      for (const written of keyframes) {
        const keyframe = form.keyframes[written.position - 1]

        if (!keyframe || !targets.has(keyframe)) continue

        Object.assign(keyframe, {
          title: written.title,
          description: written.description,
          spatial: written.spatial,
          elements: written.elements.length > 0 ? written.elements : keyframe.elements,
        })
      }
    }

    chat.message = ''
  } catch {
    chat.error = $t('The director could not change the plan. Please try again.')
  } finally {
    targets.forEach((_, keyframe) => (keyframe.pending = false))
    chat.busy = false
  }
}
</script>
