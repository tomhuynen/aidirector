<template>
  <aside class="flex w-[22rem] shrink-0 flex-col gap-6 overflow-y-auto border-l border-border p-6">
    <div class="space-y-1">
      <p class="text-xs font-medium tracking-wide text-muted-foreground uppercase">{{ $t('Cast & sets') }}</p>
      <h2 class="text-xl font-semibold">{{ $t('Keep these consistent?') }}</h2>
      <p class="text-sm leading-relaxed text-muted-foreground">
        {{
          $t(
            'These people, places and objects appear in this shot. Added ones get a reference image and look the same in every shot that uses them.',
          )
        }}
      </p>
    </div>

    <p v-if="pending" class="flex items-center gap-2 text-sm text-signal">
      <LoaderCircle class="size-4 animate-spin" />
      {{ $t('Finding the cast and sets in keyframe 1…') }}
    </p>

    <template v-else>
      <ul class="space-y-4">
        <li
          v-for="(proposal, i) in proposals"
          :key="i"
          :class="
            cn(
              'space-y-3 rounded-xl border border-border bg-card px-4 py-4',
              decisions[i].action === 'skip' && 'opacity-60',
            )
          "
        >
          <div class="flex items-start gap-3">
            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-signal-soft text-signal">
              <component :is="iconFor(proposal.type)" class="size-4" />
            </span>
            <div class="min-w-0 space-y-1">
              <p class="font-semibold">{{ proposal.name }}</p>
              <p class="text-sm leading-relaxed text-muted-foreground">{{ proposal.description }}</p>
              <p class="text-xs text-muted-foreground tabular-nums">
                {{ $t('In keyframe :list', { list: proposal.keyframes.join(', ') }) }}
              </p>
            </div>
          </div>
          <NativeSelect
            v-model="decisions[i].choice"
            class="w-full"
            :aria-label="$t('What to do with :name', { name: proposal.name })"
          >
            <NativeSelectOption value="add">{{ $t('Add to cast & sets') }}</NativeSelectOption>
            <NativeSelectOption v-for="element in sameType(proposal.type)" :key="element.id" :value="element.id">
              {{ $t('Same as :name', { name: element.name }) }}
            </NativeSelectOption>
            <NativeSelectOption value="skip">{{ $t('Skip') }}</NativeSelectOption>
          </NativeSelect>
        </li>
      </ul>

      <InputError :message="form.errors.decisions" />

      <Button type="button" class="w-full" :disabled="form.processing" @click="confirm">
        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
        {{ $t('Confirm and draw the other keyframes') }}
      </Button>
    </template>
  </aside>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { NativeSelect, NativeSelectOption } from '@shared:ui/native-select'
import { Box, LoaderCircle, MapPin, User } from 'lucide-vue-next'
import { computed, reactive } from 'vue'

export type ElementProposal = {
  name: string
  type: string
  description: string
  keyframes: number[]
  match: string | null
}

export type LibraryElement = { id: string; type: string; name: string }

const props = defineProps<{
  proposals: ElementProposal[]
  library: LibraryElement[]
  pending: boolean
  reviewUrl: string
}>()

/**
 * A matched proposal starts on its existing element, everything else on adding it.
 */
const decisions = reactive(
  props.proposals.map((proposal) => ({
    choice: proposal.match ?? 'add',
    get action() {
      return this.choice === 'add' || this.choice === 'skip' ? this.choice : 'existing'
    },
  })),
)

const sameType = (type: string) => props.library.filter((element) => element.type === type)

const icons: Record<string, typeof User> = { person: User, place: MapPin, object: Box }

const iconFor = (type: string) => icons[type] ?? Box

const payload = computed(() =>
  decisions.map((decision) =>
    decision.action === 'existing' ? { action: 'existing', element: decision.choice } : { action: decision.action },
  ),
)

const form = useForm<{ decisions: { action: string; element?: string }[] }>({ decisions: [] })

const confirm = () =>
  form.transform(() => ({ decisions: payload.value })).post(props.reviewUrl, { preserveScroll: true })
</script>
