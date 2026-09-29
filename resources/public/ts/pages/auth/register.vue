<template>
  <Head :title="$t('Create account')" />

  <form class="grid gap-6" @submit.prevent="submit">
    <FormInput
      v-if="inviteRequired"
      v-model="form.invite_code"
      required
      :label="$t('Invite code')"
      autocomplete="off"
      :error="form.errors.invite_code"
    />
    <FormInput
      v-model="form.name"
      required
      autofocus
      :label="$t('Name')"
      autocomplete="name"
      :error="form.errors.name"
    />
    <FormInput
      v-model="form.email"
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
      autocomplete="new-password"
      :minlength="minPasswordLength"
      :error="form.errors.password"
    >
      <template #help>
        <small class="text-xs text-muted-foreground">{{
          $t('Use at least :count characters.', { count: String(minPasswordLength) })
        }}</small>
      </template>
    </FormInput>
    <FormInput
      v-model="form.password_confirmation"
      required
      :label="$t('Confirm password')"
      type="password"
      autocomplete="new-password"
      :error="form.errors.password_confirmation"
    />

    <Button type="submit" class="w-full" :disabled="form.processing">
      <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
      {{ $t('Create account') }}
    </Button>
  </form>

  <p class="text-center text-sm text-muted-foreground">
    {{ $t('Already have an account?') }}
    <TextLink :href="login.url()">{{ $t('Log in') }}</TextLink>
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
import { computed, h } from 'vue'

defineOptions({
  layout: (_: unknown, page: unknown) =>
    h(AuthLayout, { title: $t('Create your account'), description: $t('A few details and you are in.') }, () => page),
})

const props = defineProps<{
  inviteRequired: boolean
  passwordRules: Record<string, number | boolean>
}>()

const minPasswordLength = computed(() => (props.passwordRules.min as number) ?? 12)

const form = useForm({
  invite_code: '',
  name: '',
  email: '',
  password: '',
  password_confirmation: '',
})

const submit = () => {
  form.post('/auth/register', {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>
