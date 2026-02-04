<template>
  <SidebarMenu>
    <SidebarMenuItem>
      <DropdownMenu>
        <DropdownMenuTrigger as-child>
          <SidebarMenuButton
            size="lg"
            class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
          >
            <Avatar class="h-8 w-8 rounded-lg">
              <AvatarFallback
                v-if="page.props.isImpersonated"
                class="rounded-lg bg-conic from-indigo-600 to-purple-400 to-50% animate-pulse"
              >
                <Drama class="size-4" />
              </AvatarFallback>
              <template v-else>
                <AvatarImage v-if="user.avatar" :src="user.avatar" :alt="user.name" />
                <AvatarFallback class="rounded-lg">{{ initials }}</AvatarFallback>
              </template>
            </Avatar>
            <div class="grid flex-1 text-left text-sm leading-tight">
              <span class="truncate font-semibold">{{ user.name }}</span>
              <span class="truncate text-xs">{{ user.email }}</span>
            </div>
            <ChevronsUpDown class="ml-auto size-4" />
          </SidebarMenuButton>
        </DropdownMenuTrigger>
        <DropdownMenuContent
          class="w-[--reka-dropdown-menu-trigger-width] min-w-56 rounded-lg"
          :side="isMobile ? 'bottom' : state === 'collapsed' ? 'left' : 'bottom'"
          align="end"
          :side-offset="4"
        >
          <DropdownMenuLabel class="p-0 font-normal">
            <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
              <Avatar class="h-8 w-8 rounded-lg">
                <AvatarImage v-if="user.avatar" :src="user.avatar" :alt="user.name" />
                <AvatarFallback class="rounded-lg">{{ initials }}</AvatarFallback>
              </Avatar>
              <div class="grid flex-1 text-left text-sm leading-tight">
                <span class="truncate font-semibold">{{ user.name }}</span>
                <span class="truncate text-xs">{{ user.email }}</span>
              </div>
            </div>
          </DropdownMenuLabel>
          <DropdownMenuSeparator />
          <DropdownMenuGroup>
            <DropdownMenuItem v-if="page.props.isImpersonated" :as-child="true" class="w-full">
              <Link href="/admin/impersonate/leave" class="w-full">
                <Drama />
                {{ $t('Stop Impersonating') }}
              </Link>
            </DropdownMenuItem>
            <DropdownMenuItem :as-child="true" class="w-full">
              <Link href="/admin/settings/profile" class="w-full">
                <Settings />
                {{ $t('Settings') }}
              </Link>
            </DropdownMenuItem>
            <DropdownMenuItem :as-child="true" class="w-full">
              <Link href="/auth/logout" method="post" class="w-full">
                <LogOut />
                {{ $t('Log out') }}
              </Link>
            </DropdownMenuItem>
          </DropdownMenuGroup>
        </DropdownMenuContent>
      </DropdownMenu>
    </SidebarMenuItem>
  </SidebarMenu>
</template>

<script setup lang="ts">
import type { PageProps } from '@admin/ts/types/shared'
import { $t } from '@admin:shared/i18n'
import { Link, usePage } from '@inertiajs/vue3'
import { Avatar, AvatarFallback, AvatarImage } from '@shared:ui/avatar'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@shared:ui/dropdown-menu'
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@shared:ui/sidebar'
import { ChevronsUpDown, Drama, LogOut, Settings } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
  user: {
    name: string
    email: string
    avatar?: string
  }
}>()

const page = usePage<PageProps>()

const { isMobile, state } = useSidebar()
const initials = computed(() =>
  props.user.name
    .split(' ')
    .map((name) => name[0])
    .join(''),
)
</script>
