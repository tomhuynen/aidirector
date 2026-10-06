<template>
  <header class="flex h-16 shrink-0 items-center justify-between gap-6 border-b border-border bg-background px-5">
    <nav class="flex min-w-0 items-center gap-3 text-[15px]" :aria-label="$t('Breadcrumb')">
      <Link :href="account ? account.links.projects : app.route" class="flex items-center gap-3">
        <span class="flex size-8 items-center justify-center rounded-full bg-signal">
          <span class="size-3 rounded-full border-[3px] border-primary-foreground" />
        </span>
        <span class="text-lg font-semibold">{{ app.title }}</span>
      </Link>
      <span class="text-muted-foreground/50">/</span>
      <template v-for="(crumb, i) in crumbs" :key="i">
        <ChevronRight v-if="i > 0" class="size-4 text-muted-foreground/60" />
        <Link
          v-if="crumb.href"
          :href="crumb.href"
          class="truncate text-muted-foreground transition-colors hover:text-foreground"
          >{{ crumb.title }}</Link
        >
        <span v-else class="truncate font-semibold">{{ crumb.title }}</span>
      </template>
    </nav>

    <div class="flex shrink-0 items-center gap-2">
      <Button v-if="decisions" as-child variant="outline" size="sm">
        <Link :href="decisions.url">
          <ListChecks class="size-4" />
          {{ $t('Decisions') }}
          <span
            :class="
              decisions.count > 0
                ? 'rounded-full bg-signal px-1.5 text-xs font-semibold text-primary-foreground tabular-nums'
                : 'text-xs text-muted-foreground tabular-nums'
            "
            >{{ decisions.count }}</span
          >
        </Link>
      </Button>
      <NotificationsMenu v-if="account" :account="account" />
      <AccountMenu v-if="account" :account="account" />
      <Button v-if="closeHref" as-child variant="ghost" size="icon-sm" :aria-label="$t('Close')">
        <Link :href="closeHref"><X class="size-5" /></Link>
      </Button>
    </div>
  </header>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import AccountMenu from '@public:components/AccountMenu.vue'
import NotificationsMenu from '@public:components/NotificationsMenu.vue'
import { Button } from '@shared:ui/button'
import { ChevronRight, ListChecks, X } from 'lucide-vue-next'

import { usePage } from '../../composables/page'

export type Crumb = { title: string; href?: string }

defineProps<{
  crumbs: Crumb[]
  closeHref?: string
  /** The project's decision queue and how many decisions wait in it. */
  decisions?: { url: string; count: number }
}>()

const { account, app } = usePage()
</script>
