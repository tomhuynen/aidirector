<template>
  <Head :title="$t('Forgot password?')" />

  <form @submit.prevent="submit">
    <FormInput
      v-model="form.email"
      :label="$t('Email')"
      type="email"
      autocomplete="off"
      placeholder="email@example.com"
      :error="form.errors.email"
    />

    <Button type="submit" class="mt-4 w-full" :disabled="form.processing">
      <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
      {{ $t('Email password reset link') }}
    </Button>
  </form>

  <div class="space-x-1 text-center text-sm text-muted-foreground">
    <span>{{ $t('Or, return to') }}</span>
    <TextLink href="/auth/login">{{ $t('log in') }}</TextLink>
  </div>
</template>
<script setup lang="ts">
import FormInput from '@admin:components/Form/Input.vue'
import TextLink from '@admin:components/TextLink.vue'
import AuthBaseLayout from '@admin:layouts/Auth.vue'
import { $t } from '@admin:shared/i18n'
import type { PostRequest } from '@admin:types/utils'
import { Head, useForm } from '@inertiajs/vue3'
import { Button } from '@shared:ui/button'
import { LoaderCircle } from 'lucide-vue-next'
import { toast } from 'vue-sonner'

defineOptions({
  layout: AuthBaseLayout,
})

const form = useForm<PostRequest<'/auth/forgot-password'>>({
  email: '',
})

const submit = () => {
  form.post('/auth/forgot-password', {
    onSuccess: () => {
      toast.success($t('Password reset link sent to your email'))
      form.reset()
    },
  })
}
</script>
