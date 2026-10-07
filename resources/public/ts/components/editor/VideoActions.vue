<template>
  <div>
    <DropdownMenu>
      <DropdownMenuTrigger as-child>
        <Button type="button" variant="outline" size="icon" :aria-label="$t('Video actions')">
          <EllipsisVertical class="size-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" class="w-48">
        <slot />
        <DropdownMenuItem v-if="downloadUrl" as-child>
          <a :href="downloadUrl" download>
            <Download class="size-4" />
            {{ $t('Download') }}
          </a>
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  </div>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { Button } from '@shared:ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@shared:ui/dropdown-menu'
import { Download, EllipsisVertical } from 'lucide-vue-next'

/**
 * The actions for a video behind one three-dot button: actions for the video
 * itself, like rendering it again, go in the default slot as menu items,
 * then downloading it. The parent places the button.
 */
defineProps<{
  downloadUrl?: string | null
}>()
</script>
