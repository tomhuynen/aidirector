<template>
  <HeadingSmall :title="$t('Profile')" :description="$t('Update your account information and preferences.')" />

  <form class="space-y-6" @submit.prevent="submit">
    <FormInput v-model="form.name" :label="$t('Name')" :error="form.errors.name" :placeholder="$t('Name')" />
    <FormInput
      v-model="form.email"
      :label="$t('Email')"
      :error="form.errors.email"
      :placeholder="$t('Email')"
      type="email"
      autocomplete="username"
    />

    <div class="flex items-center gap-4">
      <Button :disabled="form.processing">{{ $t('Save') }}</Button>

      <Transition
        enter-active-class="transition ease-in-out"
        enter-from-class="opacity-0"
        leave-active-class="transition ease-in-out"
        leave-to-class="opacity-0"
      >
        <p v-show="form.recentlySuccessful" class="text-sm text-neutral-600">{{ $t('Saved.') }}</p>
      </Transition>
    </div>
  </form>

  <DeleteUser />
</template>
<script setup lang="ts">
import FormInput from '@admin:components/Form/Input.vue'
import HeadingSmall from '@admin:components/HeadingSmall.vue'
import DeleteUser from '@admin:components/Settings/DeleteUser.vue'
import SettingsLayout from '@admin:layouts/settings/Layout.vue'
import { $t } from '@admin:shared/i18n'
import type { App, Inertia, PageProps } from '@admin:types/utils'
import { useForm } from '@inertiajs/vue3'
import { Button } from '@shared:ui/button'
import { update } from '@wayfinder/App/Http/Controllers/Admin/Settings/ProfileController'

defineOptions({
  layout: [SettingsLayout],
})

defineProps<PageProps<Inertia.Pages.Settings.Profile>>()

const form = useForm<App.Http.Controllers.Admin.Settings.ProfileController.Update.Request>({
  name: '',
  email: '',
})

const submit = () => {
  form.patch(update.url(), {
    preserveScroll: true,
  })
}
</script>
