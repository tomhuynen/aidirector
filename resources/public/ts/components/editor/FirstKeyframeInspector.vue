<template>
  <aside class="flex w-[22rem] shrink-0 flex-col gap-6 overflow-y-auto border-l border-border p-6">
    <div class="space-y-1">
      <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ $t('Keyframe 1') }}</p>
      <h2 class="text-xl font-semibold">{{ keyframe.title }}</h2>
    </div>

    <div class="space-y-3 rounded-xl border border-signal/40 bg-signal-soft/30 px-4 py-4 text-sm leading-relaxed">
      <p class="font-semibold">{{ $t('Choose the first keyframe') }}</p>
      <p class="text-muted-foreground">
        {{
          $t(
            'The first keyframe sets the character, the place and the look for the whole shot. Pick the option you like best; the other keyframes are then drawn to match it.',
          )
        }}
      </p>
    </div>

    <div class="space-y-1.5">
      <p class="text-sm text-muted-foreground">{{ $t('Description') }}</p>
      <p class="rounded-lg border border-border bg-card px-3.5 py-2.5 text-[15px] leading-relaxed">
        {{ keyframe.description }}
      </p>
    </div>
  </aside>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'

defineProps<{
  keyframe: { title: string; description: string }
}>()
</script>
