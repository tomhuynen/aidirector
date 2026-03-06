<template>
  <HeadingSmall
    :title="$t('Password')"
    :description="$t('Ensure your account is using a long, random password to stay secure.')"
  />

  <form class="space-y-6" @submit.prevent="submit">
    <FormInput
      v-model="form.current_password"
      :label="$t('Current Password')"
      :error="form.errors.current_password"
      :placeholder="$t('Current Password')"
      type="password"
      autocomplete="current-password"
    />

    <FormInput
      v-model="form.password"
      :label="$t('New Password')"
      :error="form.errors.password"
      :placeholder="$t('New Password')"
      type="password"
      autocomplete="new-password"
    />

    <FormInput
      v-model="form.password_confirmation"
      :label="$t('Confirm Password')"
      :error="form.errors.password_confirmation"
      :placeholder="$t('Confirm Password')"
      type="password"
      autocomplete="new-password"
    />

    <footer class="flex items-center gap-4">
      <Button :disabled="form.processing">{{ $t('Save password') }}</Button>

      <Transition
        enter-active-class="transition ease-in-out"
        enter-from-class="opacity-0"
        leave-active-class="transition ease-in-out"
        leave-to-class="opacity-0"
      >
        <p v-show="form.recentlySuccessful" class="text-sm text-neutral-600">
          {{ $t('Saved.') }}
        </p>
      </Transition>
    </footer>
  </form>
</template>
<script setup lang="ts">
import HeadingSmall from '@admin/ts/components/HeadingSmall.vue'
import FormInput from '@admin:components/Form/Input.vue'
import SettingsLayout from '@admin:layouts/settings/Layout.vue'
import { $t } from '@admin:shared/i18n'
import type { Inertia } from '@admin:types/utils'
import { useForm } from '@inertiajs/vue3'
import { update } from '@routes/admin/settings/password'
import { Button } from '@shared:ui/button'

defineOptions({
  layout: [SettingsLayout],
})

const form = useForm<Inertia.Requests.Settings.Password.Update>({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const submit = () => {
  form.submit(update(), {
    preserveScroll: true,
  })
}
</script>
