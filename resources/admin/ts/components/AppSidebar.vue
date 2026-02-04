<template>
  <Sidebar collapsible="icon" variant="inset">
    <SidebarHeader>
      <AppSidebarTenantSwitcher />
    </SidebarHeader>
    <SidebarContent>
      <AppSidebarMain :navigation="navigation" />
    </SidebarContent>
    <SidebarFooter>
      <AppSidebarFooter :user="account" />
    </SidebarFooter>
  </Sidebar>
</template>
<script setup lang="ts">
import AppSidebarFooter from '@admin:components/AppSidebar/Footer.vue'
import AppSidebarMain from '@admin:components/AppSidebar/Main.vue'
import AppSidebarTenantSwitcher from '@admin:components/AppSidebar/TenantSwitcher.vue'
import type { Account, NavigationGroup } from '@admin:types/shared'
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader } from '@shared:ui/sidebar'

withDefaults(
  defineProps<{
    account: Account
    navigation?: NavigationGroup[]
  }>(),
  {
    navigation: () => [],
  },
)
</script>
