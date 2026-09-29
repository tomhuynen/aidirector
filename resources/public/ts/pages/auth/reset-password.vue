<template>
  <Head :title="$t('Choose a new password')" />

  <form class="grid gap-6" @submit.prevent="submit">
    <FormInput v-model="form.email" :label="$t('Email')" type="email" autocomplete="email" readonly />

    <FormInput
      v-model="form.password"
      required
      autofocus
      :label="$t('New password')"
      type="password"
      autocomplete="new-password"
      :minlength="minPasswordLength"
      :error="form.errors.password"
    >
      <template #help>
        <small class="flex items-center gap-2 text-xs text-muted-foreground">
          {{ $t('Use at least :count characters.', { count: String(minPasswordLength) }) }}
          <Button type="button" variant="link" size="sm" class="h-auto px-0 text-xs" @click="useSuggestion">
            {{ $t('Suggest one') }}
          </Button>
        </small>
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
      {{ $t('Save password') }}
    </Button>
  </form>
</template>
<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'
import AuthLayout from '@public/ts/layouts/Auth.vue'
import { $t } from '@public/ts/shared/i18n'
import FormInput from '@public:components/Form/Input.vue'
import { store } from '@routes/public/auth/reset-password'
import { Button } from '@shared:ui/button'
import { LoaderCircle } from 'lucide-vue-next'
import { computed, h } from 'vue'

defineOptions({
  layout: (_: unknown, page: unknown) => h(AuthLayout, { title: $t('Choose a new password') }, () => page),
})

const props = defineProps<{
  token: string
  email: string
  passwordRules: Record<string, number | boolean>
  suggestion: string
}>()

const minPasswordLength = computed(() => (props.passwordRules.min as number) ?? 12)

const form = useForm({
  token: props.token,
  email: props.email,
  password: '',
  password_confirmation: '',
})

const useSuggestion = () => {
  form.password = props.suggestion
  form.password_confirmation = props.suggestion
}

const submit = () => {
  form.post(store.url(), {
    onFinish: () => form.reset('password', 'password_confirmation'),
  })
}
</script>
