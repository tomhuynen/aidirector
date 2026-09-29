<template>
  <Dialog v-model:open="open">
    <DialogTrigger as-child>
      <slot name="trigger" />
    </DialogTrigger>
    <DialogContent>
      <form class="space-y-6" @submit.prevent="submit">
        <DialogHeader class="space-y-3">
          <DialogTitle class="font-display text-2xl font-medium">{{ title }}</DialogTitle>
          <DialogDescription>{{ description }}</DialogDescription>
        </DialogHeader>
        <DialogFooter class="gap-2">
          <DialogClose as-child>
            <Button type="button" variant="secondary">{{ $t('Cancel') }}</Button>
          </DialogClose>
          <Button type="submit" variant="destructive" :disabled="form.processing">{{ $t('Delete') }}</Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { Button } from '@shared:ui/button'
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@shared:ui/dialog'
import { ref } from 'vue'

const props = defineProps<{
  action: string
  title: string
  description: string
}>()

const open = ref(false)
const form = useForm({})

const submit = () => {
  form.delete(props.action, {
    onSuccess: () => {
      open.value = false
    },
  })
}
</script>
