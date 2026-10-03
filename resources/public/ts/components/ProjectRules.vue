<template>
  <section class="space-y-4">
    <div class="space-y-1">
      <h2 class="text-xl font-semibold">{{ $t('Learned from your corrections') }}</h2>
      <p class="text-sm text-muted-foreground">
        {{
          $t(
            'When the same kind of correction comes back in different shots, it is suggested here as a rule for the whole project.',
          )
        }}
      </p>
    </div>

    <ul class="space-y-2">
      <li
        v-for="rule in rules"
        :key="rule.id"
        :class="
          cn(
            'flex items-start justify-between gap-4 rounded-xl border px-4 py-3',
            rule.status === 'suggested' ? 'border-signal/40 bg-signal-soft/30' : 'border-border bg-card',
          )
        "
      >
        <div class="min-w-0 space-y-1">
          <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">
            {{ rule.status === 'suggested' ? $t('Suggested') : $t('In use') }}
          </p>
          <p class="text-[15px] leading-relaxed">{{ rule.text }}</p>
        </div>
        <div class="flex shrink-0 items-center gap-2">
          <Button
            v-if="rule.status === 'suggested'"
            type="button"
            size="sm"
            :disabled="busy"
            @click="post(rule.acceptUrl)"
          >
            <Check class="size-4" />
            {{ $t('Use this rule') }}
          </Button>
          <Button type="button" variant="ghost" size="sm" :disabled="busy" @click="post(rule.dismissUrl)">
            {{ rule.status === 'suggested' ? $t('Dismiss') : $t('Remove') }}
          </Button>
        </div>
      </li>
    </ul>
  </section>
</template>
<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Check } from 'lucide-vue-next'
import { ref } from 'vue'

defineProps<{
  rules: { id: string; text: string; status: string; acceptUrl: string; dismissUrl: string }[]
}>()

const busy = ref(false)

const post = (url: string) => {
  busy.value = true
  router.post(url, {}, { preserveScroll: true, only: ['rules'], onFinish: () => (busy.value = false) })
}
</script>
