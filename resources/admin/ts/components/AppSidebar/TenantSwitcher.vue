<template>
  <SidebarMenu>
    <SidebarMenuItem>
      <DropdownMenu v-if="tenants && currentTenant">
        <DropdownMenuTrigger as-child>
          <SidebarMenuButton
            size="lg"
            class="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
          >
            <div
              class="flex aspect-square size-8 items-center justify-center rounded-lg bg-sidebar-primary text-sidebar-primary-foreground"
            >
              <Building class="size-4" />
            </div>
            <div class="grid flex-1 text-left text-sm leading-tight">
              <span class="truncate font-semibold">
                {{ currentTenant.name }}
              </span>
              <span class="truncate text-xs text-muted-foreground">{{ currentTenant.domain }}</span>
            </div>
            <ChevronsUpDown class="ml-auto" />
          </SidebarMenuButton>
        </DropdownMenuTrigger>
        <DropdownMenuContent
          class="w-[--reka-dropdown-menu-trigger-width] min-w-56 rounded-lg"
          align="start"
          :side="isMobile ? 'bottom' : 'right'"
          :side-offset="4"
        >
          <DropdownMenuItem
            v-for="tenant in tenants"
            :key="tenant.name"
            class="gap-2 p-2"
            @click="switchTenant(tenant)"
          >
            <div>
              {{ tenant.name }}
              <span class="text-xs block text-muted-foreground truncate">{{ tenant.domain }}</span>
            </div>
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem class="gap-2 p-2" as-child>
            <Link href="/admin/tenants/create">
              <div class="flex size-6 items-center justify-center rounded-md border bg-background">
                <Plus class="size-4" />
              </div>
              <div class="font-medium text-muted-foreground">
                {{ $t('Add Tenant') }}
              </div>
            </Link>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </SidebarMenuItem>
  </SidebarMenu>
</template>
<script setup lang="ts">
import { useSetting } from '@admin:composables/settings'
import { $t } from '@admin:shared/i18n'
import type { Tenant } from '@admin:types/utils'
import { Link, router } from '@inertiajs/vue3'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@shared:ui/dropdown-menu'
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@shared:ui/sidebar'
import { Building, ChevronsUpDown, Plus } from 'lucide-vue-next'
import { computed } from 'vue'

const tenants = useSetting<Tenant[]>('tenants')
const currentTenant = computed(() => tenants.value.find((tenant) => tenant.isCurrent))

const { isMobile } = useSidebar()

const switchTenant = (tenant: Tenant) => {
  if (!tenant.links) {
    return
  }

  router.patch(
    tenant.links.switch,
    {
      tenant: tenant.id,
    },
    {
      onSuccess: () => {
        location.reload()
      },
    },
  )
}
</script>
