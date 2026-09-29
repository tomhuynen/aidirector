<template>
  <Link
    :href="href"
    :method="method"
    :as="as"
    class="text-foreground underline decoration-border underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current"
  >
    <slot />
  </Link>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

type Method = 'get' | 'post' | 'put' | 'patch' | 'delete'

defineProps<{
  href: string
  method?: Method
  as?: string
}>()
</script>
