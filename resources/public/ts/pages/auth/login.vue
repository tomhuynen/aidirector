<template>
  <Head :title="$t('Log in')" />

  <form class="grid gap-6" @submit.prevent="submit">
    <FormInput
      v-model="form.email"
      autofocus
      required
      :label="$t('Email')"
      type="email"
      autocomplete="email"
      placeholder="you@example.com"
      :error="form.errors.email"
    />
    <FormInput
      v-model="form.password"
      required
      :label="$t('Password')"
      type="password"
      autocomplete="current-password"
      :error="form.errors.password"
    >
      <template #label="{ id }">
        <div class="flex items-center justify-between">
          <Label :for="id" class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{
            $t('Password')
          }}</Label>
          <TextLink v-if="canResetPassword" :href="forgotPassword.url()" class="text-sm">{{
            $t('Forgot password?')
          }}</TextLink>
        </div>
      </template>
    </FormInput>

    <FormCheckbox v-model="form.remember" :label="$t('Remember me')" />

    <Button type="submit" class="w-full" :disabled="form.processing">
      <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
      {{ $t('Log in') }}
    </Button>
  </form>

  <p class="text-center text-sm text-muted-foreground">
    {{ $t('New here?') }}
    <TextLink :href="register.url()">{{ $t('Create an account') }}</TextLink>
  </p>
</template>
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthLayout from '@public/ts/layouts/Auth.vue'
import { $t } from '@public/ts/shared/i18n'
import FormCheckbox from '@public:components/Form/Checkbox.vue'
import FormInput from '@public:components/Form/Input.vue'
import TextLink from '@public:components/TextLink.vue'
import { forgotPassword, register } from '@routes/public/auth'
import { Button } from '@shared:ui/button'
import { Label } from '@shared:ui/label'
import { LoaderCircle } from 'lucide-vue-next'
import { h } from 'vue'

defineOptions({
  layout: (_: unknown, page: unknown) =>
    h(AuthLayout, { title: $t('Welcome back'), description: $t('Log in to continue with your projects.') }, () => page),
})

defineProps<{ canResetPassword: boolean }>()

const form = useForm({
  email: '',
  password: '',
  remember: false,
})

const submit = () => {
  form
    .transform((data) => ({ ...data, remember: data.remember ? 'on' : '' }))
    .post('/auth/login', {
      onFinish: () => form.reset('password'),
    })
}
</script>
