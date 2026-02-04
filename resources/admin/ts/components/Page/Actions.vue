<template>
  <aside class="ml-auto">
    <DropdownMenu>
      <DropdownMenuTrigger as-child>
        <Button variant="ghost" size="icon" class="h-7 w-7 data-[state=open]:bg-accent">
          <MoreHorizontal class="h-4 w-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" class="w-56">
        <DropdownMenuLabel>{{ $t('Actions') }}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
          <!-- Prepend slot for client-side actions -->
          <slot name="prepend" />

          <!-- Server-side actions -->
          <slot />

          <!-- Append slot for client-side actions -->
          <slot name="append" />
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  </aside>
</template>

<script setup lang="ts">
import { $t } from '@admin:shared/i18n'
import { Button } from '@shared:ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@shared:ui/dropdown-menu'
import { MoreHorizontal } from 'lucide-vue-next'
</script>
