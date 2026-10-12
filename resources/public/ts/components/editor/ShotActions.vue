<template>
  <div>
    <DropdownMenu>
      <DropdownMenuTrigger as-child>
        <Button type="button" variant="outline" size="icon" :aria-label="$t('Shot actions')">
          <EllipsisVertical class="size-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" class="w-56">
        <DropdownMenuItem :disabled="!placeUrl" @select="asking = 'place'">
          <MapPin class="size-4" />
          {{ $t('Pick another place') }}
        </DropdownMenuItem>
        <DropdownMenuItem :disabled="!planUrl" @select="asking = 'plan'">
          <RotateCcw class="size-4" />
          {{ $t('Start over') }}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>

    <Dialog :open="asking !== null" @update:open="(open: boolean) => !open && (asking = null)">
      <DialogContent>
        <form class="space-y-6" @submit.prevent="restart">
          <DialogHeader class="space-y-3">
            <DialogTitle class="font-display text-2xl font-medium">{{
              asking === 'plan' ? $t('Start over?') : $t('Pick another place?')
            }}</DialogTitle>
            <DialogDescription>{{
              asking === 'plan'
                ? $t(
                    'The plan and the drawn keyframes are thrown away. The takeaway stays, and the chat asks what should happen in this shot.',
                  )
                : $t('The plan stays. The drawn keyframes are thrown away and you choose a place again.')
            }}</DialogDescription>
          </DialogHeader>
          <InputError :message="form.errors.restart" />
          <DialogFooter class="gap-2">
            <DialogClose as-child>
              <Button type="button" variant="secondary">{{ $t('Cancel') }}</Button>
            </DialogClose>
            <Button type="submit" variant="destructive" :disabled="form.processing">{{
              asking === 'plan' ? $t('Start over') : $t('Pick another place')
            }}</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  </div>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
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
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@shared:ui/dropdown-menu'
import { EllipsisVertical, MapPin, RotateCcw } from 'lucide-vue-next'
import { ref } from 'vue'

/**
 * Starting the shot over behind one three-dot button, each asked once more
 * since what is drawn goes: another place for the same plan, or a new plan.
 * An action that cannot be done right now, such as while drawing, is greyed out.
 */
const props = defineProps<{
  placeUrl?: string | null
  planUrl?: string | null
}>()

const asking = ref<'place' | 'plan' | null>(null)
const form = useForm<{ restart?: string }>({})

const restart = () => {
  const url = asking.value === 'plan' ? props.planUrl : props.placeUrl

  if (url) form.post(url, { preserveScroll: true, onSuccess: () => (asking.value = null) })
}
</script>
