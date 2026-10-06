<template>
  <div class="flex items-center gap-2">
    <slot />

    <Dialog v-model:open="open">
      <DialogTrigger as-child>
        <Button type="button" variant="outline" size="sm">
          <Maximize2 class="size-4" />
          {{ $t('Watch bigger') }}
        </Button>
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

    <Button v-if="downloadUrl" variant="outline" size="sm" as-child>
      <a :href="downloadUrl" download>
        <Download class="size-4" />
        {{ $t('Download') }}
      </a>
    </Button>
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { Button } from '@shared:ui/button'
import { Dialog, DialogContent, DialogTitle, DialogTrigger } from '@shared:ui/dialog'
import { Download, Maximize2 } from 'lucide-vue-next'
import { ref } from 'vue'

/**
 * The buttons over a video, each with a label: watch it bigger in a dialog,
 * where the video takes 80% of the screen height, or download it. Buttons
 * for the video itself, like rendering it again, go in the default slot,
 * before these. The parent places the row, beside the video, never over it.
 */
defineProps<{
  videoUrl: string
  downloadUrl?: string | null
  title: string
}>()

const open = ref(false)
</script>
