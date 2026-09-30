<template>
  <aside class="flex w-[22rem] shrink-0 flex-col gap-6 overflow-y-auto border-l border-border p-6">
    <div class="space-y-1">
      <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ $t('Video') }}</p>
      <h2 class="text-xl font-semibold">{{ $t('Storyboard and prompt') }}</h2>
    </div>

    <div class="space-y-2">
      <p class="text-sm text-muted-foreground">{{ $t('Keyframe collage') }}</p>
      <a v-if="collageUrl" :href="collageUrl" target="_blank" rel="noopener noreferrer" class="block">
        <img
          :src="collageUrl"
          :alt="$t('Keyframe collage')"
          class="w-full rounded-lg border border-border bg-white transition-opacity hover:opacity-90"
        />
      </a>
      <p v-else class="flex items-center gap-2 text-sm text-muted-foreground">
        <LoaderCircle v-if="pending" class="size-4 animate-spin" />
        {{ pending ? $t('Composing the collage…') : $t('No collage yet.') }}
      </p>
    </div>

    <div class="space-y-2">
      <p class="text-sm text-muted-foreground">{{ $t('Prompt sent to the video model') }}</p>
      <p
        v-if="prompt"
        class="rounded-lg border border-border bg-card px-3.5 py-3 text-[13px] leading-relaxed whitespace-pre-line"
      >
        {{ prompt }}
      </p>
      <p v-else class="flex items-center gap-2 text-sm text-muted-foreground">
        <LoaderCircle v-if="pending" class="size-4 animate-spin" />
        {{ pending ? $t('Writing the prompt…') : $t('No prompt yet.') }}
      </p>
    </div>
  </aside>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { LoaderCircle } from 'lucide-vue-next'

defineProps<{
  collageUrl: string | null
  prompt: string | null
  pending: boolean
}>()
</script>
