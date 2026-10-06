<template>
  <div class="flex min-h-svh flex-col">
    <header class="border-b border-border/70 bg-background/90 backdrop-blur">
      <div class="mx-auto flex h-14 w-full max-w-6xl items-center justify-between gap-6 px-6">
        <div class="flex items-center gap-6">
          <Link :href="account ? account.links.projects : app.route" class="font-display text-lg font-medium">
            {{ app.title }}
          </Link>
          <nav v-if="account" class="hidden items-center gap-5 text-sm text-muted-foreground sm:flex">
            <Link :href="account.links.projects" class="transition-colors hover:text-foreground">{{
              $t('Projects')
            }}</Link>
          </nav>
        </div>
        <div v-if="account" class="flex items-center gap-2">
          <NotificationsMenu :account="account" />
          <AccountMenu :account="account" />
        </div>
      </div>
    </header>

    <main
      :class="
        wide
          ? 'relative flex w-full flex-1 flex-col px-6 py-8 md:px-12'
          : 'relative mx-auto flex w-full max-w-6xl flex-1 flex-col px-6 py-12 md:py-16'
      "
    >
      <slot />
    </main>
  </div>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import AccountMenu from '@public:components/AccountMenu.vue'
import NotificationsMenu from '@public:components/NotificationsMenu.vue'

import { usePage } from '../composables/page'

withDefaults(
  defineProps<{
    /** Use the full window width, for pages that are mostly pictures. */
    wide?: boolean
  }>(),
  { wide: false },
)

const { account, app } = usePage()
</script>
