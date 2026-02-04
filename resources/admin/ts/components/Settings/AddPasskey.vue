<template>
  <Dialog v-slot="{ close }">
    <DialogTrigger as-child>
      <Button size="sm">
        <Fingerprint class="size-4" />
        {{ $t('Add passkey') }}
      </Button>
    </DialogTrigger>
    <DialogContent>
      <form class="space-y-8" @submit.prevent="generatePasskey(close)">
        <DialogHeader>
          <DialogTitle>
            {{ $t('Add a passkey') }}
          </DialogTitle>
          <DialogDescription>
            {{ $t('Add a passkey to your account.') }}
          </DialogDescription>
        </DialogHeader>

        <FormInput
          v-model="form.name"
          :label="$t('Passkey nickname')"
          :placeholder="$t('My passkey')"
          minlength="3"
          required
          autocomplete="off"
        />

        <DialogFooter class="gap-2">
          <Button type="submit">
            <Fingerprint class="size-4" />
            {{ $t('Add passkey') }}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  </Dialog>
</template>
<script setup lang="ts">
import FormInput from '@admin:components/Form/Input.vue'
import { http } from '@admin:shared/http'
import { $t } from '@admin:shared/i18n'
import { router, useForm } from '@inertiajs/vue3'
import { Button } from '@shared:ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@shared:ui/dialog'
import { startRegistration } from '@simplewebauthn/browser'
import { Fingerprint } from 'lucide-vue-next'
import { toast } from 'vue-sonner'

const form = useForm({
  name: '',
})

const generatePasskey = async (close: () => void) => {
  const { data: options } = await http.get('/admin/settings/passkeys/options/create')

  try {
    const response = await startRegistration(options)

    http
      .post('/admin/settings/passkeys/create', {
        options: JSON.stringify(options),
        passkey: JSON.stringify(response),
        name: form.name,
      })
      .then(() => {
        router.reload()
        toast.success($t('Passkey added successfully.'))
        close()
      })
  } catch (error: any) {
    if (error.name === 'InvalidStateError') {
      toast.error($t('This passkey is already registered.'))
      return
    }

    if (error.name === 'NotAllowedError') {
      toast.error($t('Passkey creation cancelled.'))
      return
    }

    toast.error('passkey creation failed')
    throw error
  }
}
</script>
