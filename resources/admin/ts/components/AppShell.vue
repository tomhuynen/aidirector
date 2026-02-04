<template>
  <SidebarProvider :default-open="isOpen" :open="isOpen" @update:open="handleSidebarChange">
    <slot />
  </SidebarProvider>
</template>
<script setup lang="ts">
import { SidebarProvider } from '@shared:ui/sidebar'
import { onMounted, ref } from 'vue'

const isOpen = ref(true)

// TODO: Replace with vueuse
onMounted(() => {
  isOpen.value = localStorage.getItem('sidebar') !== 'false'
})

const handleSidebarChange = (open: boolean) => {
  isOpen.value = open
  localStorage.setItem('sidebar', String(open))
}
</script>
