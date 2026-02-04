<template>
  <Page :title="$t('Update account')" :description="account.name">
    <form class="max-w-xl space-y-6" @submit.prevent="submit">
      <FormInput v-model="form.name" :label="$t('Name')" :error="form.errors.name" :placeholder="$t('Name')" />
      <FormInput v-model="form.email" :label="$t('Email')" :error="form.errors.email" :placeholder="$t('Email')" />

      <Card>
        <CardHeader>
          <CardTitle>{{ $t('Roles') }}</CardTitle>
          <CardDescription class="text-sm text-muted-foreground">
            {{ $t('Select the roles for the account') }}
          </CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
          <FormCheckbox
            v-for="role in roles"
            :key="role.name"
            class="items-start space-x-1"
            :model-value="form.roles.includes(role.name)"
            :value="role.name"
            :label="role.name"
            @update:model-value="handleRoleChange(role.name)"
          >
            <div class="grid gap-1.5 leading-none">
              <p class="text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70">
                {{ role.title }}
              </p>
              <p class="text-sm text-muted-foreground whitespace-pre-wrap">
                {{ role.description }}
              </p>
            </div>
          </FormCheckbox>
        </CardContent>
      </Card>

      <footer class="flex items-center gap-4">
        <Button :disabled="form.processing">{{ $t('Save account') }}</Button>

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
  </Page>
</template>
<script setup lang="ts">
import AppLayout from '@admin/ts/layouts/App.vue'
import type { GetResponse, PostRequest } from '@admin/ts/types/utils'
import FormCheckbox from '@admin:components/Form/Checkbox.vue'
import FormInput from '@admin:components/Form/Input.vue'
import Page from '@admin:components/Page.vue'
import { $t } from '@admin:shared/i18n'
import { useForm } from '@inertiajs/vue3'
import { Button } from '@shared:ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@shared:ui/card'
import { toast } from 'vue-sonner'

defineOptions({
  layout: [AppLayout],
})

const props = defineProps<{
  account: GetResponse<'/admin/accounts/{account}/update'>['account']
  roles: GetResponse<'/admin/accounts/{account}/update'>['roles']
}>()

const form = useForm<PostRequest<'/admin/accounts/{account}/update'>>({
  name: props.account?.name ?? '',
  email: props.account?.email ?? '',
  roles: props.account?.roles.map((role) => role.name) ?? [],
})

const handleRoleChange = (role: string) => {
  if (form.roles.includes(role)) {
    form.roles = form.roles.filter((r) => r !== role)
  } else {
    form.roles.push(role)
  }
}

const submit = () => {
  form.post(location.pathname, {
    only: ['tenant'],
    onSuccess: () => {
      toast.success($t('Account saved.'))
    },
  })
}
</script>
