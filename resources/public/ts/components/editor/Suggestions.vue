<template>
  <section class="flex min-h-0 flex-1 flex-col p-6">
    <form
      class="mx-auto flex min-h-0 w-full max-w-3xl flex-col rounded-xl border border-border bg-card/60"
      @submit.prevent="choose"
    >
      <header class="flex shrink-0 items-start justify-between gap-4 px-6 py-5">
        <div class="space-y-1">
          <h2 class="text-2xl font-semibold">{{ $t('Suggested storylines') }}</h2>
          <p class="text-[15px] leading-relaxed text-muted-foreground">
            {{ $t('Here are three possible storylines for this shot.') }}<br />
            {{ $t('Select one to continue, or give feedback to generate new options.') }}
          </p>
        </div>
        <Button as-child variant="ghost" size="icon-sm" :aria-label="$t('Edit brief')">
          <Link :href="editUrl"><X class="size-5" /></Link>
        </Button>
      </header>

      <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 pb-6">
        <p v-if="error" class="rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm">
          {{ error }}
        </p>

        <div role="radiogroup" :aria-label="$t('Suggested storylines')" class="space-y-4">
          <label
            v-for="(option, index) in options"
            :key="index"
            class="flex cursor-pointer gap-4 rounded-lg border border-border bg-background/60 p-5 transition-colors has-checked:border-signal has-checked:bg-signal-soft/40 has-focus-visible:ring-2 has-focus-visible:ring-ring"
          >
            <input
              v-model="form.option"
              type="radio"
              name="option"
              :value="index"
              class="mt-1 size-5 shrink-0 accent-signal"
            />
            <span class="min-w-0 space-y-1">
              <span class="block text-lg font-semibold">{{ index + 1 }}. {{ option.title }}</span>
              <span class="block text-[15px] leading-relaxed text-muted-foreground">{{ option.storyline }}</span>
            </span>
          </label>
        </div>
        <InputError :message="form.errors.option" />

        <div class="space-y-2">
          <label :for="feedbackId" class="block text-[15px] font-medium">{{ $t('Not quite right?') }}</label>
          <textarea
            :id="feedbackId"
            v-model="feedback.feedback"
            rows="2"
            maxlength="500"
            :placeholder="$t('Tell AI what you’d like to change…')"
            class="block w-full rounded-lg border border-input bg-background px-4 py-3 text-[15px] leading-relaxed placeholder:text-muted-foreground/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
          />
          <InputError :message="feedback.errors.feedback" />
        </div>
      </div>

      <footer class="flex shrink-0 items-center gap-3 px-6 pb-6">
        <Button type="button" variant="outline" class="flex-1" :disabled="busy" @click="regenerate">
          <RefreshCw class="size-4" :class="feedback.processing && 'animate-spin'" />
          {{ $t('Generate new suggestions') }}
        </Button>
        <Button type="submit" class="min-w-56" :disabled="busy || form.option === null">
          <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
          {{ $t('Continue') }}
          <ArrowRight v-if="!form.processing" class="size-4" />
        </Button>
      </footer>
    </form>
  </section>
</template>
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { Button } from '@shared:ui/button'
import { ArrowRight, LoaderCircle, RefreshCw, X } from 'lucide-vue-next'
import { computed, useId } from 'vue'

const props = defineProps<{
  options: { title: string; storyline: string }[]
  error?: string | null
  editUrl: string
  suggestUrl: string
  chooseUrl: string
}>()

const feedbackId = useId()

const form = useForm<{ option: number | null }>({ option: null })
const feedback = useForm({ feedback: '' })

const busy = computed(() => form.processing || feedback.processing)

const choose = () => {
  form.post(props.chooseUrl, { preserveScroll: true })
}

const regenerate = () => {
  feedback.post(props.suggestUrl, { preserveScroll: true })
}
</script>
