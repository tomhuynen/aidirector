<template>
  <div class="space-y-6">
    <HeadingSmall title="Delete account" description="Delete your account and all of its resources" />
    <div class="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
      <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
        <p class="font-medium">{{ $t('Warning') }}</p>
        <p class="text-sm">{{ $t('Please proceed with caution, this cannot be undone.') }}</p>
      </div>
      <Dialog>
        <DialogTrigger as-child>
          <Button variant="destructive">{{ $t('Delete account') }}</Button>
        </DialogTrigger>
        <DialogContent>
          <form class="space-y-6" @submit="deleteUser">
            <DialogHeader class="space-y-3">
              <DialogTitle>{{ $t('Are you sure you want to delete your account?') }}</DialogTitle>
              <DialogDescription>
                {{
                  $t(
                    'Once your account is deleted, all of its resources and data will also be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
                  )
                }}
              </DialogDescription>
            </DialogHeader>

            <FormInput
              ref="passwordInput"
              v-model="form.password"
              :label="$t('Password')"
              :error="form.errors.password"
              :placeholder="$t('Password')"
              type="password"
              autocomplete="current-password"
            />

            <DialogFooter class="gap-2">
              <DialogClose as-child>
                <Button variant="secondary" @click="closeModal"> {{ $t('Cancel') }} </Button>
              </DialogClose>

              <Button variant="destructive" :disabled="form.processing">
                <button type="submit">{{ $t('Delete account') }}</button>
              </Button>
            </DialogFooter>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  </div>
</template>
<script setup lang="ts">
import FormInput from '@admin:components/Form/Input.vue'
import HeadingSmall from '@admin:components/HeadingSmall.vue'
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
import { ref } from 'vue'

const passwordInput = ref<HTMLInputElement | null>(null)

const form = useForm({
  password: '',
})

const deleteUser = (e: Event) => {
  e.preventDefault()

  form.delete('/admin/settings/profile', {
    preserveScroll: true,
    onSuccess: () => closeModal(),
    onError: () => passwordInput.value?.focus(),
    onFinish: () => form.reset(),
  })
}

const closeModal = () => {
  form.clearErrors()
  form.reset()
}
</script>
