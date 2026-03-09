<template>
  <Head :title="$t('Activate account')" />

  <form @submit.prevent="submit">
    <div class="grid gap-6">
      <FormInput
        :model-value="email"
        :label="$t('Email')"
        type="email"
        autocomplete="email"
        readonly
        class="cursor-not-allowed text-muted-foreground"
      />

      <FormInput
        v-model="form.password"
        :label="$t('Password')"
        type="password"
        autocomplete="new-password"
        :minlength="minPassLength"
        :maxlength="maxPassLength"
        :placeholder="$t('Password')"
        required
        :error="form.errors.password"
      >
        <template #help>
          <small class="text-xs text-muted-foreground">
            {{ $t('Use at least :t characters.', { t: String(minPassLength) }) }}
            <Button variant="link" size="sm" class="px-0 text-xs" @click="useSuggestion">{{
              $t('Suggest a password')
            }}</Button>
          </small>
        </template>
      </FormInput>

      <FormInput
        v-model="form.password_confirmation"
        :label="$t('Confirm password')"
        type="password"
        autocomplete="new-password"
        required
        :error="form.errors.password_confirmation"
      />

      <Button type="submit" class="mt-4 w-full" :disabled="form.processing">
        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
        {{ $t('Activate account') }}
      </Button>
    </div>
  </form>
</template>
<script setup lang="ts">
import FormInput from '@admin:components/Form/Input.vue'
import AuthBaseLayout from '@admin:layouts/Auth.vue'
import { $t } from '@admin:shared/i18n'
import type { Inertia } from '@admin:types/utils'
import { Head, useForm } from '@inertiajs/vue3'
import { invite } from '@routes/admin/auth'
import { Button } from '@shared:ui/button'
import { LoaderCircle } from 'lucide-vue-next'
import { computed } from 'vue'
import { toast } from 'vue-sonner'

defineOptions({
  layout: AuthBaseLayout,
})

const props = defineProps<Inertia.Pages.Auth.Invite>()

const minPassLength = computed(() => props.passwordRules.min as number)
const maxPassLength = computed(() => (props.passwordRules.max || 50) as number)

const form = useForm<Inertia.Requests.Auth.Invite.Store>({
  password: '',
  password_confirmation: '',
})

const useSuggestion = () => {
  form.password = props.suggestion
  form.password_confirmation = props.suggestion
}

const submit = () => {
  form.post(invite.url(props.email), {
    only: ['errors'],
    onSuccess: () => {
      toast.success($t('Password reset successfully.'))
    },
  })
}
</script>
