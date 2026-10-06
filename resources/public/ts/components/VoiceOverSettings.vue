<template>
  <section class="space-y-5 rounded-xl border border-border bg-card p-6">
    <div class="flex items-start gap-3">
      <Checkbox
        id="voice-over-enabled"
        :model-value="enabled"
        class="mt-0.5"
        @update:model-value="(value) => save(value === true, locales)"
      />
      <div class="space-y-1">
        <Label for="voice-over-enabled" class="text-base font-semibold">{{ $t('Voice-over') }}</Label>
        <p class="text-sm text-muted-foreground">
          {{ $t('Add a spoken voice-over to the videos, in the languages you choose.') }}
        </p>
      </div>
    </div>

    <fieldset v-if="enabled" class="space-y-3 border-t border-border pt-5">
      <legend class="sr-only">{{ $t('Languages') }}</legend>
      <p class="text-sm font-medium">
        {{ $t('Languages') }}
        <span class="font-normal text-muted-foreground tabular-nums">({{ locales.length }})</span>
      </p>
      <ul class="grid grid-cols-1 gap-x-6 gap-y-2.5 sm:grid-cols-2 lg:grid-cols-4">
        <li v-for="language in languages" :key="language.code" class="flex items-center gap-2.5">
          <Checkbox
            :id="`voice-over-${language.code}`"
            :model-value="locales.includes(language.code)"
            @update:model-value="(value) => toggle(language.code, value === true)"
          />
          <Label :for="`voice-over-${language.code}`" class="flex min-w-0 items-center gap-2 font-normal">
            <span aria-hidden="true" class="text-base leading-none">{{ flag(language.code) }}</span>
            <span class="truncate">{{ language.name }}</span>
          </Label>
        </li>
      </ul>
    </fieldset>
  </section>
</template>
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { Checkbox } from '@shared:ui/checkbox'
import { Label } from '@shared:ui/label'
import { ref, watch } from 'vue'

const props = defineProps<{
  enabled: boolean
  locales: string[]
  languages: { code: string; name: string }[]
  saveUrl: string
}>()

/* Shown straight away; the server's answer replaces it. */
const enabled = ref(props.enabled)
const locales = ref([...props.locales])

watch(
  () => [props.enabled, props.locales] as const,
  ([isEnabled, chosen]) => {
    enabled.value = isEnabled
    locales.value = [...chosen]
  },
)

/** The flag of the locale's country, from its region code: "nl-NL" becomes 🇳🇱. */
const flag = (code: string) =>
  (code.split('-')[1] ?? '')
    .toUpperCase()
    .replace(/./g, (letter) => String.fromCodePoint(127397 + letter.charCodeAt(0)))

const save = (isEnabled: boolean, chosen: string[]) => {
  enabled.value = isEnabled
  locales.value = chosen
  router.post(
    props.saveUrl,
    { enabled: isEnabled, locales: chosen },
    { preserveScroll: true, preserveState: true, only: ['project'] },
  )
}

const toggle = (code: string, checked: boolean) =>
  save(enabled.value, checked ? [...locales.value, code] : locales.value.filter((locale) => locale !== code))
</script>
