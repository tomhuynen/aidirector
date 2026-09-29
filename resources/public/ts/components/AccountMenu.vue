<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" size="sm" class="gap-2 font-normal">
        <span class="hidden sm:inline">{{ account.name }}</span>
        <ChevronDown class="size-4 text-muted-foreground" />
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-56">
      <DropdownMenuLabel class="font-normal">
        <p class="text-sm font-medium">{{ account.name }}</p>
        <p class="truncate text-xs text-muted-foreground">{{ account.email }}</p>
      </DropdownMenuLabel>
      <DropdownMenuSeparator />
      <DropdownMenuItem as-child>
        <Link :href="account.links.projects">{{ $t('Projects') }}</Link>
      </DropdownMenuItem>
      <DropdownMenuSeparator />
      <DropdownMenuItem as-child>
        <Link :href="account.links.logout" method="post" as="button" class="w-full">{{ $t('Log out') }}</Link>
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import type { Account } from '@public:types/shared'
import { Button } from '@shared:ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@shared:ui/dropdown-menu'
import { ChevronDown } from 'lucide-vue-next'

defineProps<{
  account: Account
}>()
</script>
