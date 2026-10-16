<template>
  <div class="flex gap-2 text-[15px] leading-relaxed">
    <!-- Opens what the video model got. -->
    <button
      type="button"
      class="group/prompt -ml-1 flex h-4 w-4 shrink-0 cursor-pointer items-center justify-center"
      :aria-expanded="showPrompt"
      :aria-label="$t('What the video model got')"
      :title="$t('What the video model got')"
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
        {{ $t('Video · :seconds s', { seconds: String(seconds) }) }}
      </p>
      <p class="text-muted-foreground">{{ sent.summary || $t('No summary for this video.') }}</p>
      <div v-if="showPrompt" class="space-y-2 border-t border-border pt-3 text-sm">
        <p class="text-xs text-muted-foreground">{{ sent.model }}</p>
        <p class="whitespace-pre-line text-muted-foreground">{{ sent.prompt }}</p>
      </div>
    </div>
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { ref } from 'vue'

/**
 * What the video shows, in a sentence or two, next to it; the triangle
 * opens the model and the prompt it got, as for a keyframe.
 */
defineProps<{
  sent: { model: string; prompt: string; summary: string | null }
  seconds: number
}>()

const showPrompt = ref(false)
</script>
