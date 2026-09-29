<template>
  <div class="grid min-h-svh lg:grid-cols-[1.1fr_1fr]">
    <aside class="hidden flex-col justify-between border-r border-border bg-paper-deep p-10 lg:flex">
      <Link :href="app.route" class="font-display text-lg font-medium">{{ app.title }}</Link>
      <div class="max-w-md space-y-4">
        <p class="font-display text-4xl leading-[1.1] font-medium text-balance">
          {{ $t('Decide what every shot should say. Let the director work out how to say it.') }}
        </p>
        <p class="text-sm leading-relaxed text-muted-foreground">
          {{ $t('Projects, shots, camera options and keyframes, in one calm workspace.') }}
        </p>
      </div>
      <p class="text-xs text-muted-foreground">{{ app.title }}</p>
    </aside>

    <div class="flex flex-col items-center justify-center px-6 py-12">
      <div class="w-full max-w-sm space-y-8">
        <div class="space-y-2">
          <Link :href="app.route" class="font-display text-lg font-medium lg:hidden">{{ app.title }}</Link>
          <h1 class="font-display text-3xl font-medium">{{ title }}</h1>
          <p v-if="description" class="text-sm text-muted-foreground">{{ description }}</p>
        </div>
        <slot />
      </div>
    </div>
  </div>
  <Toaster position="bottom-right" />
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { Toaster } from 'vue-sonner'

import { usePage } from '../composables/page'

defineProps<{
  title: string
  description?: string
}>()

const { app } = usePage()
</script>
