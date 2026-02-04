<template>
  <Dialog @update:open="emit('update:open', $event)">
    <DialogTrigger as-child>
      <slot name="trigger" />
    </DialogTrigger>
    <DialogContent>
      <form class="space-y-6" @submit="submit">
        <DialogHeader class="space-y-3">
          <DialogTitle>
            <slot name="title">
              {{ $t('Are you sure you want to continue?') }}
            </slot>
          </DialogTitle>
          <DialogDescription>
            <slot name="description">
              {{ $t('After deleting this item, all of its resources and data will also be permanently deleted.') }}
            </slot>
          </DialogDescription>
        </DialogHeader>

        <FormInput
          v-if="confirmText"
          ref="confirmationTextRef"
          v-model="confirmationText"
          :label="$t('Confirmation')"
          :placeholder="$t('Confirmation')"
          type="password"
          autocomplete="current-password"
        >
          <template #help>
            <slot name="help">
              {{ $t('Please enter the confirmation text :confirmText to continue.', { confirmText }) }}
            </slot>
          </template>
        </FormInput>

        <DialogFooter class="gap-2">
          <DialogClose as-child>
            <Button variant="secondary">
              {{ $t('Cancel') }}
            </Button>
          </DialogClose>

          <Button variant="destructive" :disabled="form.processing">
            <button type="submit">
              {{ $t('Delete') }}
            </button>
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
<script setup lang="ts">
import FormInput from '@admin:components/Form/Input.vue'
import { $t } from '@admin:shared/i18n'
import { useForm } from '@inertiajs/vue3'
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
import { ref, useTemplateRef } from 'vue'
import { toast } from 'vue-sonner'

const props = defineProps<{
  action: string
  confirmText?: string
}>()

const emit = defineEmits(['delete', 'update:open'])

const form = useForm({})

const confirmationText = ref('')
const confirmationTextRef = useTemplateRef<HTMLInputElement>('confirmationTextRef')

const submit = (e: Event) => {
  e.preventDefault()

  form.delete(props.action, {
    preserveScroll: true,
    onSuccess: () => {
      emit('update:open', false)
      toast.success($t('Item deleted successfully.'))
    },
    onError: () => confirmationTextRef.value?.focus(),
    onFinish: () => form.reset(),
  })
}
</script>
