<template>
  <Page :eyebrow="project.purposeLabel" :title="project.title" :description="project.description ?? undefined">
    <template #actions>
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
      </div>
    </section>

    <VoiceOverSettings
      :enabled="project.voiceOver"
      :locales="project.voiceOverLocales"
      :languages="voiceOverLanguages"
      :save-url="project.links?.voiceOver ?? '#'"
    />

    <Branding :logos="branding.logos" :store-url="branding.storeUrl" />

    <CastAndSets :elements="elements" :types="elementTypes" :create-url="project.links?.elementsCreate ?? '#'" />

    <ProjectRules v-if="rules.length > 0" :rules="rules" />
  </Page>
</template>
<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import AppLayout from '@public/ts/layouts/App.vue'
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import Branding from '@public:components/Branding.vue'
import CastAndSets from '@public:components/CastAndSets.vue'
import FormatPicker from '@public:components/FormatPicker.vue'
import Page from '@public:components/Page.vue'
import ProjectRules from '@public:components/ProjectRules.vue'
import VoiceOverSettings from '@public:components/VoiceOverSettings.vue'
import { Button } from '@shared:ui/button'
import { ArrowRight } from 'lucide-vue-next'
import { computed } from 'vue'

defineOptions({
  layout: AppLayout,
})

const props = defineProps<Inertia.Pages.Projects.View>()

const headerImage = computed(() => props.project.coverUrl ?? props.project.styleReferenceUrl)

const reducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches
</script>
