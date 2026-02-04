<template>
  <Head title="Login" />

  <form class="flex flex-col gap-4" @submit.prevent="form.post('')">
    <div class="grid gap-6">
      <FormInput
        v-model="form.email"
        autofocus
        required
        :label="$t('Email')"
        :tabindex="1"
        type="email"
        autocomplete="email"
        placeholder="email@example.com"
        :error="form.errors.email"
      />
      <FormInput
        v-model="form.password"
        required
        :label="$t('Password')"
        :tabindex="2"
        type="password"
        autocomplete="current-password"
        :placeholder="$t('Password')"
        :error="form.errors.password"
      >
        <template #label="{ id }">
          <div class="flex items-center justify-between">
            <Label :for="id">{{ $t('Password') }}</Label>
            <TextLink v-if="canResetPassword" href="/auth/forgot-password" class="text-sm" :tabindex="5">
              {{ $t('Forgot password?') }}
            </TextLink>
          </div>
        </template>
      </FormInput>

      <Button type="submit" class="mt-4 w-full" :disabled="form.processing">
        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
        {{ $t('Login') }}
      </Button>

      <fieldset v-if="passKeysSupported" class="passkey">
        <Divider class="mb-6">{{ $t('or') }}</Divider>

        <Button variant="secondary" class="w-full" type="button" @click.prevent="usePasskey">
          <Fingerprint class="size-4" />
          {{ $t('Log in using a passkey') }}
        </Button>
      </fieldset>

      <FormCheckbox v-model="form.remember" :label="$t('Remember me')" :error="form.errors.remember" />
    </div>
  </form>
</template>
<script setup lang="ts">
import Divider from '@admin:components/Divider.vue'
import FormCheckbox from '@admin:components/Form/Checkbox.vue'
import FormInput from '@admin:components/Form/Input.vue'
import TextLink from '@admin:components/TextLink.vue'
import AuthBaseLayout from '@admin:layouts/Auth.vue'
import { http } from '@admin:shared/http'
import { $t } from '@admin:shared/i18n'
import type { PostRequest } from '@admin:types/utils'
import { Head, router, useForm } from '@inertiajs/vue3'
import { Button } from '@shared:ui/button'
import { Label } from '@shared:ui/label'
import { browserSupportsWebAuthn, startAuthentication } from '@simplewebauthn/browser'
import { Fingerprint, LoaderCircle } from 'lucide-vue-next'
import { computed } from 'vue'
import { toast } from 'vue-sonner'

defineOptions({
  layout: AuthBaseLayout,
})

defineProps<{ canResetPassword: boolean }>()

const passKeysSupported = computed(() => browserSupportsWebAuthn())

const form = useForm<PostRequest<'/auth/login'> & { remember: boolean }>({
  email: '',
  password: '',
  remember: false,
})

const usePasskey = async () => {
  const { data: options } = await http.get('/auth/passkeys/options/auth')
  let response

  try {
    response = await startAuthentication({ optionsJSON: options })
  } catch {
    toast.error('Passkey authentication failed.')
    return
  }

  http
    .post('/auth/passkeys/login', {
      start_authentication_response: JSON.stringify(response),
      remember: form.remember,
    })
    .then((response) => {
      router.visit(response.data.url)
    })
    .catch((error) => {
      const message = error.response?.data?.error ?? 'Could not login using the given passkey.'
      toast.error(message)
    })
}
</script>
