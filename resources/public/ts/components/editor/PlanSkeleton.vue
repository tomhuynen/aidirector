<template>
  <!-- The plan editor's shape while the director drafts the storyline and keyframes. -->
  <section class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto p-6" aria-busy="true">
    <div class="flex w-full max-w-3xl flex-col gap-6">
      <header class="space-y-1">
        <h2 class="text-2xl font-semibold">{{ $t('The plan') }}</h2>
        <p class="flex items-center gap-2 text-muted-foreground">
          <LoaderCircle class="size-4 animate-spin text-signal" />
          {{
            $t(
              'The director is drafting the storyline and the keyframes. You can change everything before anything is drawn.',
            )
          }}
        </p>
      </header>

      <div class="space-y-2">
        <Skeleton class="h-4 w-20" />
        <Skeleton class="h-24 w-full" />
      </div>

      <div class="grid gap-4 rounded-xl border border-border p-4 sm:grid-cols-2">
        <Skeleton class="h-10 w-full" />
        <Skeleton class="h-10 w-full" />
        <Skeleton class="h-10 w-full sm:col-span-2" />
        <Skeleton class="h-10 w-full sm:col-span-2" />
      </div>

      <div v-for="n in 3" :key="n" class="space-y-3 rounded-xl border border-border bg-card/60 p-4">
        <div class="flex items-center gap-3">
          <Skeleton class="size-7 rounded-full" />
          <Skeleton class="h-10 flex-1" />
        </div>
        <Skeleton class="h-20 w-full" />
        <Skeleton class="h-16 w-full" />
        <div class="flex gap-2">
          <Skeleton class="h-8 w-36 rounded-full" />
          <Skeleton class="h-8 w-32 rounded-full" />
        </div>
      </div>
    </div>
  </section>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import { Skeleton } from '@shared:ui/skeleton'
import { LoaderCircle } from 'lucide-vue-next'
</script>
