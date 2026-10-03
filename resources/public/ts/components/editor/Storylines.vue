<template>
  <section class="flex min-h-0 flex-1 flex-col overflow-y-auto p-8">
    <header class="flex items-start justify-between gap-6">
      <div class="space-y-2">
        <h2 class="text-3xl font-semibold">{{ $t('Suggested storylines') }}</h2>
        <p class="text-lg leading-relaxed text-muted-foreground">
          {{ $t('Here are :count possible storylines for this shot.', { count: String(options.length) }) }}<br />
          {{ $t('Select one to continue, or give feedback to generate new options.') }}
        </p>
      </div>
      <Button as-child variant="ghost">
        <Link :href="editUrl">
          <Pencil class="size-4" />
          {{ $t('Edit brief') }}
        </Link>
      </Button>
    </header>

    <p v-if="error" class="mt-6 rounded-lg border border-destructive/50 bg-destructive/10 px-4 py-3 text-sm">
      {{ error }}
    </p>

    <RadioGroup v-model="selected" class="mt-8 gap-4">
      <label
        v-for="(option, i) in options"
        :key="i"
        :class="
          cn(
            'flex cursor-pointer items-start gap-5 rounded-xl border border-border bg-card/60 px-6 py-5 transition-colors hover:border-muted-foreground/50',
            selected === String(i) && 'border-signal bg-signal-soft/40 hover:border-signal',
          )
        "
      >
        <RadioGroupItem :value="String(i)" class="mt-1 size-6 border-2" />
        <span class="min-w-0 space-y-1">
          <span class="block text-xl font-semibold">{{ i + 1 }}. {{ option.title }}</span>
          <span class="block text-[17px] leading-relaxed text-muted-foreground">{{ option.storyline }}</span>
        </span>
      </label>
    </RadioGroup>

    <div class="mt-8 space-y-2">
      <p class="text-[15px] font-medium">{{ $t('Not quite right?') }}</p>
      <textarea
        v-model="feedback.feedback"
        rows="2"
        maxlength="500"
        :placeholder="$t('Tell the director what you would like to change…')"
        class="block w-full rounded-lg border border-input bg-background px-4 py-3 text-[15px] leading-relaxed placeholder:text-muted-foreground/70 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
      />
      <InputError :message="feedback.errors.feedback" />
    </div>

    <footer class="mt-auto flex items-center justify-between gap-3 pt-8">
      <Button
        v-if="!hasFeedback"
        type="button"
        variant="outline"
        size="lg"
        :disabled="feedback.processing"
        @click="regenerate"
      >
        <RefreshCw class="size-4" :class="feedback.processing && 'animate-spin'" />
        {{ $t('Generate new suggestions') }}
      </Button>
      <Button
        type="button"
        size="lg"
        class="ml-auto"
        :disabled="hasFeedback ? feedback.processing : selected === undefined || choice.processing"
        @click="hasFeedback ? regenerate() : choose()"
      >
        <template v-if="hasFeedback">
          <RefreshCw class="size-4" :class="feedback.processing && 'animate-spin'" />
          {{ $t('Generate new suggestions with this feedback') }}
        </template>
        <template v-else>
          {{ $t('Continue') }}
          <ArrowRight class="size-4" />
        </template>
      </Button>
    </footer>
  </section>
</template>
<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { RadioGroup, RadioGroupItem } from '@shared:ui/radio-group'
import { ArrowRight, Pencil, RefreshCw } from 'lucide-vue-next'
import { computed, ref } from 'vue'

const props = defineProps<{
  options: { title: string; storyline: string }[]
  error?: string | null
  editUrl: string
  suggestUrl: string
  chooseUrl: string
}>()

const selected = ref<string | undefined>(props.options.length > 0 ? '0' : undefined)

const feedback = useForm({ feedback: '' })
const choice = useForm({ option: 0 })

/**
 * With feedback typed, the way on is a new set of suggestions that uses it,
 * so the feedback can never be lost by continuing with an old one.
 */
const hasFeedback = computed(() => feedback.feedback.trim() !== '')

const regenerate = () => feedback.post(props.suggestUrl, { preserveScroll: true })

const choose = () => {
  if (selected.value === undefined) return

  choice.transform(() => ({ option: Number(selected.value) })).post(props.chooseUrl)
}
</script>
