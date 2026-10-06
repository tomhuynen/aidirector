<template>
  <Page :eyebrow="project.purposeLabel" :title="project.title" :description="project.description ?? undefined">
    <template #actions>
      <Button v-if="project.links?.decisions" as-child variant="outline">
        <Link :href="project.links.decisions">
          <ListChecks class="size-4" />
          {{ $t('Decisions') }}
          <span
            v-if="decisionsCount > 0"
            class="rounded-full bg-signal px-1.5 text-xs font-semibold text-primary-foreground tabular-nums"
            >{{ decisionsCount }}</span
          >
        </Link>
      </Button>
      <Button as-child>
        <Link :href="project.links?.editor ?? project.links?.shotsCreate ?? '#'">
          {{ shots.length > 0 ? $t('Open editor') : $t('Add the first shot') }}
          <ArrowRight class="size-4" />
        </Link>
      </Button>
    </template>

    <!-- The cast and sets group picture, or the chosen style sheet when the cast was skipped. -->
    <figure v-if="headerImage" class="overflow-hidden rounded-xl border border-border bg-card">
      <!-- Once made, the cover loops gently; the still stays for viewers who prefer less motion. -->
      <video
        v-if="project.coverLoopUrl && !reducedMotion"
        :src="project.coverLoopUrl"
        :poster="headerImage"
        autoplay
        muted
        loop
        playsinline
        disablepictureinpicture
        aria-hidden="true"
        class="h-48 w-full object-cover md:h-64"
      />
      <img
        v-else
        :src="headerImage"
        :alt="
          project.coverUrl
            ? $t('The cast and sets of :title', { title: project.title })
            : $t('Style reference for :title', { title: project.title })
        "
        class="h-48 w-full object-cover md:h-64"
      />
    </figure>

    <section>
      <div class="space-y-5 rounded-xl border border-border bg-card p-6">
        <FormatPicker
          :formats="videoFormats"
          :aspect-ratio="project.aspectRatio"
          :resolution="project.videoResolution"
          :has-shots="shots.length > 0"
          :save-url="project.links?.format ?? '#'"
        />
        <dl v-if="project.website" class="border-t border-border pt-4 text-sm">
          <div class="space-y-1">
            <dt class="text-muted-foreground">{{ $t('Website') }}</dt>
            <dd class="truncate font-medium">
              <a
                :href="project.website"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1.5 text-signal hover:underline"
              >
                {{ project.website.replace(/^https?:\/\//, '') }}
                <ExternalLink class="size-3.5" />
              </a>
            </dd>
          </div>
        </dl>
      </div>
    </section>

    <VoiceOverSettings
      :enabled="project.voiceOver"
      :locales="project.voiceOverLocales"
      :languages="voiceOverLanguages"
      :save-url="project.links?.voiceOver ?? '#'"
    />

    <ProjectRules v-if="rules.length > 0" :rules="rules" />

    <CastAndSets :elements="elements" :types="elementTypes" :create-url="project.links?.elementsCreate ?? '#'" />
  </Page>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import CastAndSets from '@public:components/CastAndSets.vue'
import FormatPicker from '@public:components/FormatPicker.vue'
import Page from '@public:components/Page.vue'
import ProjectRules from '@public:components/ProjectRules.vue'
import VoiceOverSettings from '@public:components/VoiceOverSettings.vue'
import { Button } from '@shared:ui/button'
import { ArrowRight, ExternalLink, ListChecks } from 'lucide-vue-next'
import { computed } from 'vue'

defineOptions({
  layout: AppLayout,
})

const props = defineProps<Inertia.Pages.Projects.View>()

const headerImage = computed(() => props.project.coverUrl ?? props.project.styleReferenceUrl)

const reducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
</script>
