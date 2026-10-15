<template>
  <Dialog v-model:open="open">
    <DialogContent>
      <DialogHeader class="space-y-3">
        <DialogTitle class="font-display text-2xl font-medium">{{
          count === 1 ? $t('Also update the keyframe after it?') : $t('Also update the keyframes after it?')
        }}</DialogTitle>
        <DialogDescription>{{
          count === 1
            ? $t(
                'The next keyframe is drawn from this one. Draw it again so it follows the change, or keep it as it is.',
              )
            : $t(
                ':count keyframes are drawn from this one. Draw them again so they follow the change, or keep them as they are.',
                { count: String(count) },
              )
        }}</DialogDescription>
      </DialogHeader>
      <DialogFooter class="gap-2">
        <Button type="button" variant="outline" :disabled="processing" @click="emit('answer', true)">{{
          $t('Yes, update them')
        }}</Button>
        <Button type="button" :disabled="processing" @click="emit('answer', false)">{{
          $t('No, only this one')
        }}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { Button } from '@shared:ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@shared:ui/dialog'

/** Asks, before a change to a keyframe is drawn, whether the keyframes drawn from it follow it. */
defineProps<{
  /** How many drawn keyframes come after it. */
  count: number
  processing?: boolean
}>()

const open = defineModel<boolean>('open', { required: true })

const emit = defineEmits<{ answer: [following: boolean] }>()
</script>
