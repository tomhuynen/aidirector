<template>
  <section class="flex min-h-0 flex-1 flex-col overflow-y-auto p-6">
    <header class="flex items-start justify-between gap-6">
      <div class="space-y-1">
        <h2 class="text-2xl font-semibold">{{ $t('Keyframes') }}</h2>
        <p class="text-muted-foreground">
          {{ $t('Review the planned keyframes for this shot. Images follow in the next step.') }}
        </p>
      </div>
      <form class="flex items-center gap-2" @submit.prevent="regenerate">
        <input
          v-model="form.instruction"
          type="text"
          maxlength="500"
          :placeholder="$t('Optional instruction…')"
          class="h-10 w-64 rounded-lg border border-input bg-background px-3 text-sm placeholder:text-muted-foreground/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        />
        <Button type="submit" variant="outline" :disabled="form.processing">
          <RefreshCw class="size-4" :class="form.processing && 'animate-spin'" />
          {{ $t('Regenerate keyframes') }}
        </Button>
      </form>
    </header>

    <p v-if="error" class="mt-4 rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm">
      {{ error }}
    </p>

    <div class="mt-6 flex min-h-0 flex-1 items-center justify-center">
      <Placeholder
        class="max-h-full max-w-full rounded-xl bg-card"
        :style="{
          aspectRatio: aspectRatio.replace(':', ' / '),
          height: isPortrait ? '100%' : undefined,
          width: isPortrait ? undefined : '100%',
        }"
      >
        <div
          v-if="selected"
          class="max-w-md space-y-2 rounded-lg border border-border bg-background/90 px-5 py-4 text-center"
        >
          <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {{ $t('Keyframe :n of :total', { n: String(selectedIndex + 1), total: String(keyframes.length) }) }}
          </p>
          <h3 class="font-semibold">{{ selected.title }}</h3>
          <p class="text-sm leading-relaxed text-muted-foreground">{{ selected.description }}</p>
        </div>
      </Placeholder>
    </div>

    <ul class="mt-5 flex shrink-0 items-start gap-4 overflow-x-auto pb-1">
      <li v-for="(keyframe, i) in keyframes" :key="i" class="w-44 shrink-0 space-y-2">
        <button
          type="button"
          :class="
            cn(
              'block w-full overflow-hidden rounded-lg border border-border bg-card transition-colors hover:border-muted-foreground/60',
              i === selectedIndex && 'border-signal ring-2 ring-signal/40',
            )
          "
          @click="selectedIndex = i"
        >
          <Placeholder class="h-28 w-full rounded-none border-0 bg-card" />
        </button>
        <div class="flex items-start gap-2 px-1">
          <span
            :class="
              cn(
                'flex size-6 shrink-0 items-center justify-center rounded-full bg-secondary text-xs font-semibold tabular-nums',
                i === selectedIndex && 'bg-signal text-primary-foreground',
              )
            "
          >
            {{ i + 1 }}
          </span>
          <div class="min-w-0">
            <p class="truncate text-sm font-semibold">{{ keyframe.title }}</p>
            <p class="text-xs text-muted-foreground tabular-nums">{{ timeAt(i) }}</p>
          </div>
        </div>
      </li>
      <li class="w-44 shrink-0">
        <div
          class="flex h-28 w-full items-center justify-center rounded-lg border border-dashed border-border bg-card/60 text-muted-foreground"
        >
          <Plus class="size-6" />
        </div>
        <p class="mt-2 px-1 text-sm text-muted-foreground">{{ $t('Add keyframe') }}</p>
      </li>
    </ul>

    <footer class="mt-auto flex items-center justify-end pt-6">
      <Button size="lg" disabled :title="$t('Image generation comes next')">
        {{ $t('Continue') }}
        <ArrowRight class="size-4" />
      </Button>
    </footer>
  </section>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { ArrowRight, Plus, RefreshCw } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'

import Placeholder from './Placeholder.vue'

const props = defineProps<{
  keyframes: { title: string; description: string }[]
  aspectRatio: string
  duration: number
  error?: string | null
  generateUrl: string
}>()

const selectedIndex = ref(0)

watch(
  () => props.keyframes.length,
  (length) => {
    if (selectedIndex.value >= length) selectedIndex.value = 0
  },
)

const selected = computed(() => props.keyframes[selectedIndex.value])

const isPortrait = computed(() => {
  const [w, h] = props.aspectRatio.split(':').map(Number)

  return h >= w
})

const timeAt = (index: number) => {
  const count = Math.max(props.keyframes.length - 1, 1)

  return `${((props.duration * index) / count).toFixed(1)} s`
}

const form = useForm({ instruction: '' })

const regenerate = () => form.post(props.generateUrl, { preserveScroll: true })
</script>
