<template>
  <!-- Sized to the window, so a decision never needs scrolling: the pictures take what is left. -->
  <div class="flex h-[calc(100svh-7.5rem)] flex-col">
    <Head :title="$t('Decisions')" />

    <!-- Two columns: everything to read and do on the left, the pictures as large as they fit on the right. -->
    <section
      v-if="current"
      :key="current.id"
      class="grid min-h-0 flex-1 gap-10 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]"
    >
      <aside class="flex min-h-0 flex-col gap-5">
        <div class="min-h-0 space-y-5 overflow-y-auto">
          <Link
            :href="project.links?.view ?? '#'"
            class="flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
          >
            <ArrowLeft class="size-4" />
            {{ project.title }}
          </Link>

          <header class="space-y-2">
            <p class="flex items-center gap-2 text-xs font-medium tracking-[0.18em] text-signal uppercase">
              <component :is="headingIcon" class="size-5 text-signal" />
              {{ heading }}
            </p>
            <h2 v-if="current.shot" class="text-3xl font-semibold">
              {{ current.shot.code }} {{ current.shot.title ?? current.shot.takeaway }}
            </h2>
          </header>

          <div v-if="current.shot?.voiceOver" class="flex items-start gap-3 rounded-lg border border-border p-4">
            <Mic class="mt-0.5 size-6 shrink-0 text-signal" />
            <p class="space-y-1">
              <span class="block font-semibold">{{ $t('Voice-over') }}</span>
              <span class="block text-[15px] leading-relaxed text-muted-foreground italic"
                >“{{ current.shot.voiceOver }}”</span
              >
            </p>
          </div>

          <div v-if="current.shot?.takeaway" class="flex items-start gap-3 rounded-lg border border-border p-4">
            <Lightbulb class="mt-0.5 size-6 shrink-0 text-amber-400" />
            <p class="space-y-1">
              <span class="block font-semibold">{{ $t('Takeaway') }}</span>
              <span class="block text-[15px] leading-relaxed text-muted-foreground">{{ current.shot.takeaway }}</span>
            </p>
          </div>

          <template v-if="current.type === 'render'">
            <Button
              v-if="current.fixAllUrl && current.issueGroups.length > 1"
              type="button"
              size="sm"
              class="self-start"
              :disabled="busy"
              @click="send(current.fixAllUrl, {})"
            >
              <Wand class="size-4" />
              {{ $t('Fix all') }}
            </Button>
            <!-- One panel per keyframe; hovering one points out its keyframe on the right. -->
            <div
              v-for="group in current.issueGroups"
              :key="group.key"
              class="space-y-3 rounded-lg border border-amber-500/60 bg-black p-4 text-sm"
              @mouseenter="pointAt(group.position)"
              @mouseleave="hoveredPosition = null"
            >
              <p class="font-semibold">
                {{ group.position ? $t('Keyframe :n', { n: String(group.position) }) : $t('The whole shot') }}
              </p>
              <ul class="space-y-2">
                <li v-for="(issue, i) in group.issues" :key="i" class="flex gap-2 leading-relaxed">
                  <TriangleAlert class="mt-0.5 size-4 shrink-0 text-amber-500" />
                  {{ issue }}
                </li>
              </ul>
              <div class="flex gap-2 pl-6">
                <Button v-if="group.fixUrl" type="button" size="sm" :disabled="busy" @click="send(group.fixUrl, {})">
                  <Wand class="size-4" />
                  {{ $t('Fix') }}
                </Button>
                <Button type="button" size="sm" variant="ghost" :disabled="busy" @click="send(group.dismissUrl, {})">
                  {{ $t('Dismiss') }}
                </Button>
              </div>
            </div>
          </template>

          <p v-if="current.shot?.storyline" class="text-[15px] leading-relaxed text-muted-foreground">
            {{ current.shot.storyline }}
          </p>
          <Button v-if="current.shot" as-child variant="outline" size="sm">
            <Link :href="current.shot.url">
              <ExternalLink class="size-4" />
              {{ $t('Open shot') }}
            </Link>
          </Button>
        </div>

        <footer class="mt-auto flex shrink-0 flex-wrap gap-2">
          <Button
            v-if="current.type === 'plan'"
            type="button"
            size="lg"
            :disabled="busy"
            @click="send(current.drawUrl, {})"
          >
            <Wand class="size-4" />
            {{ $t('Draw keyframes') }}
            <ArrowRight class="size-4" />
          </Button>
          <Button
            v-else-if="current.type === 'first-keyframe'"
            type="button"
            size="lg"
            variant="outline"
            :disabled="busy"
            @click="send(current.moreUrl, {})"
          >
            <RefreshCw class="size-4" />
            {{ $t('More options') }}
          </Button>
          <Button
            v-else-if="current.type === 'render'"
            type="button"
            size="lg"
            :disabled="busy"
            @click="send(current.renderUrl, {})"
          >
            <Clapperboard class="size-4" />
            {{ $t('Render video') }}
            <ArrowRight class="size-4" />
          </Button>
          <Button
            v-else-if="current.type === 'attention'"
            type="button"
            size="lg"
            :disabled="busy"
            @click="send(current.retryUrl, {})"
          >
            <RefreshCw class="size-4" />
            {{ $t('Try again') }}
          </Button>
          <template v-else-if="current.type === 'rule'">
            <Button type="button" size="lg" :disabled="busy" @click="send(current.rule.acceptUrl, {})">
              <Check class="size-4" />
              {{ $t('Keep this rule') }}
            </Button>
            <Button type="button" size="lg" variant="ghost" :disabled="busy" @click="send(current.rule.dismissUrl, {})">
              {{ $t('Dismiss') }}
            </Button>
          </template>
        </footer>
      </aside>

      <div class="flex min-h-0 flex-col gap-5">
        <!-- A planned shot: the steps it will show, drawn once the director starts it. -->
        <ol v-if="current.type === 'plan'" class="grid min-h-0 content-start gap-3 overflow-y-auto">
          <li v-for="(step, i) in current.plan" :key="i" class="flex items-start gap-4 rounded-xl bg-card/60 px-5 py-4">
            <span :class="badge">{{ i + 1 }}</span>
            <span class="min-w-0 space-y-1">
              <span class="block font-semibold">{{ step.title }}</span>
              <span class="block text-[15px] leading-relaxed text-muted-foreground">{{ step.description }}</span>
            </span>
          </li>
        </ol>

        <!-- Pick keyframe 1. -->
        <div v-else-if="current.type === 'first-keyframe'" class="flex min-h-0 flex-1 gap-4">
          <button
            v-for="(option, i) in current.options"
            :key="option.id"
            type="button"
            :disabled="busy"
            class="flex h-full min-w-0 flex-1 flex-col items-center gap-3 transition-opacity hover:opacity-80"
            @click="send(current.chooseUrl, { [current.field ?? 'render']: option.id })"
          >
            <span :class="badge">{{ i + 1 }}</span>
            <img :src="option.imageUrl" alt="" class="min-h-0 w-full flex-1 rounded-lg object-contain object-top" />
          </button>
        </div>

        <!-- Review the keyframes before starting the video. -->
        <!-- Keyframes stay as large as the height allows; the row scrolls sideways when they do not fit. -->
        <div
          v-else-if="current.type === 'render'"
          ref="strip"
          class="flex min-h-0 flex-1 items-stretch gap-2 overflow-x-auto pb-2"
        >
          <template v-for="(keyframe, i) in current.keyframes" :key="i">
            <ChevronRight v-if="i > 0" class="size-7 shrink-0 self-center text-signal" />
            <figure
              :ref="(el) => (figures[i + 1] = el as HTMLElement | null)"
              class="flex shrink-0 flex-col gap-3"
              :style="{ width: `${frameWidth}px` }"
            >
              <figcaption class="flex items-center gap-3 font-semibold">
                <span :class="badge">{{ i + 1 }}</span>
                <span class="truncate">{{ keyframe.title }}</span>
              </figcaption>
              <img
                v-if="keyframe.imageUrl"
                :src="keyframe.imageUrl"
                alt=""
                :class="
                  cn(
                    'w-full rounded-lg object-cover ring-offset-2 ring-offset-background transition-shadow',
                    hoveredPosition === i + 1 && 'ring-2 ring-amber-500',
                  )
                "
                :style="{ height: `${frameHeight}px` }"
              />
            </figure>
          </template>
        </div>

        <!-- Confirm a rule learned from corrections. -->
        <p v-else-if="current.type === 'rule'" class="text-2xl leading-relaxed">{{ current.rule.text }}</p>

        <!-- Something failed and needs a look in the editor. -->
        <p v-else class="rounded-lg bg-destructive/10 px-4 py-3 text-[15px]">
          {{ current.message }}
        </p>

        <footer
          class="mt-auto flex shrink-0 items-center gap-4 border-t border-border pt-5 text-sm text-muted-foreground"
        >
          <span class="tabular-nums">{{ $t(':n of :total', { n: String(position), total: String(total) }) }}</span>
          <span class="h-1.5 min-w-16 flex-1 overflow-hidden rounded-full bg-muted">
            <span class="block h-full rounded-full bg-signal transition-all" :style="{ width: `${progress}%` }" />
          </span>
          <template v-if="queue.length > 1">
            <span class="h-6 w-px bg-border" />
            <button type="button" class="flex items-center gap-2 transition-colors hover:text-foreground" @click="skip">
              {{ $t('Skip for now') }}
              <kbd class="rounded border border-border px-1.5 text-xs">S</kbd>
            </button>
          </template>
        </footer>
      </div>
    </section>

    <div
      v-else
      class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-border p-12 text-center"
    >
      <CircleCheck class="size-8 text-muted-foreground" />
      <p class="text-lg">{{ $t('Nothing waits for you right now.') }}</p>
      <p class="text-muted-foreground">
        {{ $t('New decisions show up here as soon as the director has them ready.') }}
      </p>
      <Button as-child variant="outline" class="mt-3">
        <Link :href="project.links?.view ?? '#'">
          <ArrowLeft class="size-4" />
          {{ $t('Back to project') }}
        </Link>
      </Button>
    </div>
  </div>
</template>
<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import {
  ArrowLeft,
  ArrowRight,
  Check,
  ChevronRight,
  CircleCheck,
  Clapperboard,
  ExternalLink,
  Image as ImageIcon,
  Lightbulb,
  Mic,
  RefreshCw,
  Scale,
  TriangleAlert,
  Wand,
} from 'lucide-vue-next'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

defineOptions({
  layout: [AppLayout, { wide: true }],
})

type DecisionShot = {
  id: string
  code: string
  title: string | null
  takeaway: string
  storyline: string | null
  voiceOver: string | null
  url: string
}

type Decision = { id: string; waitingSince: string | null; shot?: DecisionShot } & (
  | { type: 'plan'; plan: { title: string; description: string }[]; drawUrl: string }
  | {
      type: 'first-keyframe'
      options: { id: number; imageUrl: string }[]
      field?: 'render' | 'plate'
      chooseUrl: string
      moreUrl: string
    }
  | {
      type: 'render'
      keyframes: { title: string; imageUrl: string | null }[]
      fixAllUrl: string | null
      issueGroups: {
        key: string
        position: number | null
        issues: string[]
        fixUrl: string | null
        dismissUrl: string
      }[]
      renderUrl: string
    }
  | { type: 'rule'; rule: { text: string; acceptUrl: string; dismissUrl: string } }
  | { type: 'attention'; message: string; retryUrl: string }
)

const props = defineProps<Inertia.Pages.Projects.Decisions>()

const busy = ref(false)

/*
 * The keyframe strip: each keyframe as tall as the strip allows, at the
 * project's aspect ratio, so they keep their size and the strip scrolls.
 */
const strip = ref<HTMLElement | null>(null)
const stripHeight = ref(0)
const figures: Record<number, HTMLElement | null> = {}
const hoveredPosition = ref<number | null>(null)
const CAPTION_HEIGHT = 44

const ratio = computed(() => {
  const [width, height] = (props.project.aspectRatio ?? '16:9').split(':').map(Number)

  return width && height ? width / height : 16 / 9
})

const frameHeight = computed(() => Math.max(120, stripHeight.value - CAPTION_HEIGHT - 8))
const frameWidth = computed(() => Math.round(frameHeight.value * ratio.value))

const resizeObserver =
  typeof ResizeObserver === 'undefined'
    ? null
    : new ResizeObserver(([entry]) => (stripHeight.value = entry.contentRect.height))

watch(strip, (element, previous) => {
  if (previous) resizeObserver?.unobserve(previous)
  if (element) resizeObserver?.observe(element)
})

const pointAt = (position: number | null) => {
  hoveredPosition.value = position

  if (position !== null) {
    figures[position]?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'nearest' })
  }
}

// Skipped decisions go to the back of the queue until the page is left.
const skipped = ref<string[]>([])

const queue = computed(() => {
  const decisions = props.decisions as unknown as Decision[]

  return [
    ...decisions.filter((decision) => !skipped.value.includes(decision.id)),
    ...skipped.value
      .map((id) => decisions.find((decision) => decision.id === id))
      .filter((decision) => decision !== undefined),
  ]
})

const current = computed(() => queue.value[0])

// The most decisions seen at once on this visit, so the progress counts what was dealt with.
const total = ref(props.decisions.length)

watch(
  () => props.decisions.length,
  (count) => (total.value = Math.max(total.value, count)),
)

const position = computed(() => Math.min(total.value, total.value - queue.value.length + 1))

const progress = computed(() => (total.value === 0 ? 100 : (position.value / total.value) * 100))

const badge =
  'flex size-8 shrink-0 items-center justify-center rounded-full bg-signal text-sm font-semibold text-primary-foreground tabular-nums'

const headingIcon = computed(
  () =>
    ({
      'plan': Wand,
      'first-keyframe': ImageIcon,
      'render': Clapperboard,
      'rule': Scale,
      'attention': TriangleAlert,
    })[current.value?.type ?? 'attention'],
)

const heading = computed(
  () =>
    ({
      'plan': $t('Draw the keyframes'),
      'first-keyframe':
        current.value?.type === 'first-keyframe' && current.value.options.length === 1
          ? $t('Confirm keyframe 1')
          : $t('Choose keyframe 1'),
      'render': $t('Render the video'),
      'rule': $t('Keep this rule?'),
      'attention': $t('Needs your attention'),
    })[current.value?.type ?? 'attention'],
)

const skip = () => {
  if (current.value) {
    skipped.value = [...skipped.value.filter((id) => id !== current.value!.id), current.value.id]
  }
}

const send = (url: string, data: Record<string, unknown>) => {
  busy.value = true
  router.post(url, { ...data, return: 'decisions' }, { preserveScroll: true, onFinish: () => (busy.value = false) })
}

const onKey = (event: KeyboardEvent) => {
  const decision = current.value

  if (!decision || busy.value || event.metaKey || event.ctrlKey || event.altKey) {
    return
  }

  if (event.target instanceof HTMLElement && ['INPUT', 'TEXTAREA'].includes(event.target.tagName)) {
    return
  }

  const index = Number(event.key) - 1

  if (event.key.toLowerCase() === 's') {
    skip()
  } else if (decision.type === 'first-keyframe' && decision.options[index]) {
    send(decision.chooseUrl, { [decision.field ?? 'render']: decision.options[index].id })
  } else if (decision.type === 'plan' && event.key === 'Enter') {
    send(decision.drawUrl, {})
  } else if (decision.type === 'render' && event.key === 'Enter') {
    send(decision.renderUrl, {})
  } else if (decision.type === 'attention' && event.key === 'Enter') {
    send(decision.retryUrl, {})
  }
}

usePoll(5000, { only: ['decisions'] })

onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  resizeObserver?.disconnect()
})
</script>
