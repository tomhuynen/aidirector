<template>
  <AppLayout>
    <Page :title="$t('Settings')" :description="$t('Manage your profile and account settings')">
      <div class="flex flex-col space-y-8 md:space-y-0 lg:flex-row lg:space-x-12 lg:space-y-0">
        <aside class="w-full max-w-xl lg:w-48">
          <nav class="flex flex-col space-x-0 space-y-1">
            <Button
              v-for="(link, id) in account.links"
              :key="id"
              variant="ghost"
              size="sm"
              :class="['w-full justify-start', { 'bg-muted': link.active }]"
              as-child
            >
              <Link :href="link.href">{{ link.title }}</Link>
            </Button>
          </nav>
        </aside>

        <Separator class="my-6 md:hidden" />

        <div class="flex-1 md:max-w-2xl">
          <section class="max-w-2xl space-y-8">
            <slot />
          </section>
        </div>
      </div>
    </Page>
  </AppLayout>
</template>
<script lang="ts" setup>
import AppLayout from '@admin/ts/layouts/App.vue'
import Page from '@admin:components/Page.vue'
import { usePage } from '@admin:composables/page'
import { $t } from '@admin:shared/i18n'
import { Link } from '@inertiajs/vue3'
import { Button } from '@shared:ui/button'
import { Separator } from '@shared:ui/separator'

const { account } = usePage()
</script>
