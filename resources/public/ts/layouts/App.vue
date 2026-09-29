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
        <AccountMenu v-if="account" :account="account" />
      </div>
    </header>

    <main class="mx-auto w-full max-w-6xl flex-1 px-6 py-12 md:py-16">
      <slot />
    </main>

    <footer class="mx-auto w-full max-w-6xl px-6 py-8 text-xs text-muted-foreground">
      {{ app.title }}
    </footer>
  </div>
  <Toaster position="bottom-right" />
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import AccountMenu from '@public:components/AccountMenu.vue'
import { Toaster } from 'vue-sonner'

import { usePage } from '../composables/page'

const { account, app } = usePage()
</script>
