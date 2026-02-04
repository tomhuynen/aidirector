<template>
  <AppShell>
    <AppSidebar :navigation="navigation" :account="account" />
    <AppContent>
      <slot />
    </AppContent>
  </AppShell>
  <Toaster />
</template>

<script setup lang="ts">
import AppContent from '@admin:components/AppContent.vue'
import AppShell from '@admin:components/AppShell.vue'
import AppSidebar from '@admin:components/AppSidebar.vue'
import { usePage } from '@admin:composables/page'
import { Toaster } from 'vue-sonner'

const { navigation, account } = usePage()
</script>
