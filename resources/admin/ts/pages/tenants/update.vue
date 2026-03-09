<template>
  <Page :title="tenant.id ? $t('Edit tenant :name', { name: tenant.name }) : $t('Create tenant')">
    <form class="max-w-xl space-y-6" @submit.prevent="submit">
      <FormInput
        v-model="form.name"
        :disabled="!!tenant.id"
        :label="$t('Name')"
        :error="form.errors.name"
        :placeholder="$t('My example tenant')"
      />

      <FormInput
        v-model="form.domain"
        :disabled="!!tenant.id"
        :label="$t('Domain')"
        :error="form.errors.domain"
        :placeholder="$t('example.com')"
      >
        <template #prefix>https://</template>
      </FormInput>

      <footer class="flex items-center gap-4">
        <Button :disabled="form.processing">{{ $t('Save tenant') }}</Button>

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
import type { Inertia } from '@admin/ts/types/utils'
import FormInput from '@admin:components/Form/Input.vue'
import Page from '@admin:components/Page.vue'
import { $t } from '@admin:shared/i18n'
import { useForm } from '@inertiajs/vue3'
import { store, update } from '@routes/admin/tenants'
import { Button } from '@shared:ui/button'
import { toast } from 'vue-sonner'

defineOptions({
  layout: [AppLayout],
})

const props = defineProps<Inertia.Pages.Tenants.Update>()

const form = useForm<Inertia.Requests.Tenants.Store>({
  name: props.tenant?.name ?? '',
  domain: props.tenant?.domain ?? '',
})

const submit = () => {
  form.post(props.tenant ? update.url(props.tenant.id) : store.url(), {
    only: ['tenant'],
    onSuccess: () => {
      toast.success($t('Tenant saved.'))
    },
  })
}
</script>
