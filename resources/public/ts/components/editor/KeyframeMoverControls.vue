<template>
  <section
    class="space-y-3 rounded-lg border border-signal/40 bg-signal-soft/30 p-4 text-sm"
    aria-labelledby="mover-heading"
  >
    <p id="mover-heading" class="font-semibold">{{ $t('Move a person') }}</p>
    <p v-if="!mover.state.picked" class="flex items-center gap-2 text-muted-foreground">
      <LoaderCircle v-if="mover.state.selecting" class="size-4 animate-spin text-signal" />
      {{ mover.state.selecting ? $t('Finding the person…') : $t('Click the person in the image.') }}
    </p>
    <template v-else>
      <p class="text-muted-foreground">{{ $t('Drag the person to the new spot. The size follows the depth.') }}</p>
      <div class="space-y-1.5">
        <Label for="mover-size">{{ $t('Size') }}</Label>
        <div class="flex items-center gap-3">
          <input
            id="mover-size"
            :value="mover.state.factor"
            type="range"
            min="0.6"
            max="1.6"
            step="0.02"
            class="w-full accent-[var(--color-signal)]"
            @input="mover.setFactor(Number(($event.target as HTMLInputElement).value))"
          />
          <span class="w-12 text-right tabular-nums">{{ Math.round(mover.scale.value * 100) }}%</span>
        </div>
      </div>
      <div class="space-y-1.5">
        <Label for="mover-instruction">{{ $t('What they do from there') }}</Label>
        <Textarea
          id="mover-instruction"
          :model-value="mover.state.instruction"
          rows="2"
          maxlength="500"
          :placeholder="$t('For example: looks up at the container and points at it. Empty: as the description says.')"
          @update:model-value="mover.setInstruction(String($event))"
        />
      </div>
    </template>
    <InputError :message="mover.state.error ?? undefined" />
    <div class="flex gap-2">
      <Button type="button" variant="ghost" class="flex-1" :disabled="mover.state.applying" @click="mover.cancel">
        {{ $t('Cancel') }}
      </Button>
      <Button type="button" class="flex-1" :disabled="!mover.state.picked || mover.state.applying" @click="mover.apply">
        <LoaderCircle v-if="mover.state.applying" class="size-4 animate-spin" />
        <Move v-else class="size-4" />
        {{ $t('Move it') }}
      </Button>
    </div>
  </section>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import InputError from '@public:components/Form/InputError.vue'
import { Button } from '@shared:ui/button'
import { Label } from '@shared:ui/label'
import { Textarea } from '@shared:ui/textarea'
import { LoaderCircle, Move } from 'lucide-vue-next'

import type { KeyframeMoverState } from './keyframeMover'

defineProps<{ mover: KeyframeMoverState }>()
</script>
