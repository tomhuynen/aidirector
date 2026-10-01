<template>
  <div class="space-y-10">
    <PageBackdrop :image="backdrop" />

    <ol
      class="grid max-w-3xl gap-8 rounded-2xl border border-border bg-card/70 p-8 backdrop-blur-sm sm:grid-cols-3 sm:gap-4"
    >
      <li v-for="(step, i) in steps" :key="step.title" class="relative flex flex-col items-center gap-3 text-center">
        <span
          v-if="i < steps.length - 1"
          aria-hidden="true"
          class="absolute top-7 left-[calc(50%+2.5rem)] hidden h-px w-[calc(100%-5rem)] bg-border sm:block"
        />
        <span
          :class="
            cn(
              'flex size-14 items-center justify-center rounded-full',
              i === 0 ? 'bg-signal text-primary-foreground' : 'bg-secondary text-foreground',
            )
          "
        >
          <component :is="step.icon" class="size-6" />
        </span>
        <span class="font-semibold">{{ i + 1 }}. {{ step.title }}</span>
        <span class="max-w-[14rem] text-sm leading-relaxed text-muted-foreground">{{ step.description }}</span>
      </li>
    </ol>

    <Button as-child size="lg" class="h-12 px-6 text-base">
      <Link :href="createUrl">
        {{ $t('Create your first project') }}
        <ArrowRight class="size-5" />
      </Link>
    </Button>
  </div>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import backdrop from '@public/images/projects-empty.webp'
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { ArrowRight, FileText, Play, Video } from 'lucide-vue-next'

import PageBackdrop from './PageBackdrop.vue'

defineProps<{
  createUrl: string
}>()

const steps = [
  { icon: FileText, title: $t('Define your project'), description: $t('Set the purpose, style and visual direction.') },
  { icon: Play, title: $t('Add your shots'), description: $t('Describe scenes and generate keyframes.') },
  { icon: Video, title: $t('Render your video'), description: $t('Turn your keyframes into a final video.') },
]
</script>
