<template>
  <SidebarGroup v-for="group in navigation" :key="group.title">
    <SidebarGroupLabel>{{ group.title }}</SidebarGroupLabel>
    <SidebarMenu>
      <template v-for="item in group.items" :key="item.title">
        <SidebarMenuItem v-if="!item.items?.length">
          <SidebarMenuButton as-child :is-active="item.active">
            <Primitive :as="item.external ? 'a' : Link" :href="item.href" :target="item.target">
              <Icon v-if="item.icon" :name="item.icon" />
              <span>{{ item.title }}</span>
            </Primitive>
          </SidebarMenuButton>
        </SidebarMenuItem>
        <Collapsible v-else :key="item.title" as-child :default-open="item.active" class="group/collapsible">
          <SidebarMenuItem>
            <CollapsibleTrigger as-child>
              <SidebarMenuButton :tooltip="item.title">
                <Icon v-if="item.icon" :name="item.icon" />
                <span>{{ item.title }}</span>
                <ChevronRight
                  class="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90"
                />
              </SidebarMenuButton>
            </CollapsibleTrigger>
            <CollapsibleContent>
              <SidebarMenuSub>
                <SidebarMenuSubItem v-for="subItem in item.items" :key="subItem.title">
                  <SidebarMenuSubButton as-child :is-active="subItem.active">
                    <Primitive :as="subItem.external ? 'a' : Link" :href="subItem.href" :target="subItem.target">
                      <Icon v-if="subItem.icon" :name="subItem.icon" />
                      <span>{{ subItem.title }}</span>
                    </Primitive>
                  </SidebarMenuSubButton>
                </SidebarMenuSubItem>
              </SidebarMenuSub>
            </CollapsibleContent>
          </SidebarMenuItem>
        </Collapsible>
      </template>
    </SidebarMenu>
  </SidebarGroup>
</template>
<script setup lang="ts">
import Icon from '@admin:components/Icon.vue'
import type { NavigationGroup } from '@admin:types/shared'
import { Link } from '@inertiajs/vue3'
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@shared:ui/collapsible'
import {
  SidebarGroup,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarMenuSub,
  SidebarMenuSubButton,
  SidebarMenuSubItem,
} from '@shared:ui/sidebar'
import { ChevronRight } from 'lucide-vue-next'
import { Primitive } from 'reka-ui'

defineProps<{
  navigation: NavigationGroup[]
}>()
</script>
