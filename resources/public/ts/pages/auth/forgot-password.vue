<template>
  <Head :title="$t('Forgot password')" />

  <form class="grid gap-6" @submit.prevent="submit">
    <FormInput
      v-model="form.email"
      required
      autofocus
      :label="$t('Email')"
      type="email"
      autocomplete="email"
      placeholder="you@example.com"
      :error="form.errors.email"
    />

    <Button type="submit" class="w-full" :disabled="form.processing">
      <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
      {{ $t('Email me a reset link') }}
    </Button>
  </form>

  <p class="text-center text-sm text-muted-foreground">
    {{ $t('Or return to') }}
    <TextLink :href="login.url()">{{ $t('log in') }}</TextLink>
  </p>
</template>
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthLayout from '@public/ts/layouts/Auth.vue'
import { $t } from '@public/ts/shared/i18n'
import FormInput from '@public:components/Form/Input.vue'
import TextLink from '@public:components/TextLink.vue'
import { login } from '@routes/public/auth'
import { Button } from '@shared:ui/button'
import { LoaderCircle } from 'lucide-vue-next'
import { h } from 'vue'
import { toast } from 'vue-sonner'

defineOptions({
  layout: (_: unknown, page: unknown) =>
    h(
      AuthLayout,
      { title: $t('Reset your password'), description: $t('We will email you a link to choose a new one.') },
      () => page,
    ),
})

const form = useForm({
  email: '',
})

const submit = () => {
  form.post('/auth/forgot-password', {
    onSuccess: () => {
      toast.success($t('If that address exists, a reset link is on its way.'))
      form.reset()
    },
  })
}
</script>
