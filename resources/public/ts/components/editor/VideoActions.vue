<template>
  <div
    class="absolute right-4 bottom-4 flex items-center gap-2 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100"
  >
    <Dialog v-model:open="open">
      <DialogTrigger as-child>
        <button type="button" :aria-label="$t('Watch bigger')" :title="$t('Watch bigger')" :class="iconClass">
          <Maximize2 class="size-4" />
        </button>
      </DialogTrigger>
      <DialogContent class="w-auto max-w-[calc(100%-2rem)] gap-0 border-0 bg-black p-0 sm:max-w-none">
        <DialogTitle class="sr-only">{{ title }}</DialogTitle>
        <video
          v-if="open"
          :src="videoUrl"
          controls
          autoplay
          muted
          loop
          playsinline
          class="h-[80vh] w-auto max-w-[calc(100vw-2rem)] rounded-lg object-contain"
        />
      </DialogContent>
    </Dialog>

    <a
      v-if="downloadUrl"
      :href="downloadUrl"
      download
      :aria-label="$t('Download video')"
      :title="$t('Download video')"
      :class="iconClass"
    >
      <Download class="size-4" />
    </a>
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { Dialog, DialogContent, DialogTitle, DialogTrigger } from '@shared:ui/dialog'
import { Download, Maximize2 } from 'lucide-vue-next'
import { ref } from 'vue'

/**
 * Small buttons shown on hover over a video: watch it bigger in a dialog,
 * where the video takes 80% of the screen height, or download it.
 */
defineProps<{
  videoUrl: string
  downloadUrl?: string | null
  title: string
}>()

const open = ref(false)

const iconClass =
  'flex size-8 items-center justify-center rounded-md border border-border bg-background/80 text-muted-foreground backdrop-blur-sm hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring'
</script>
