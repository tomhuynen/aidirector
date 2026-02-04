<template>
  <Base :id="id" :label="label" :error="error">
    <template #label>
      <slot :id="id" name="label" />
    </template>
    <Popover v-model:open="open">
      <PopoverTrigger as-child>
        <Button variant="outline" role="combobox" :aria-expanded="open" class="justify-between">
          {{
            model ? options.find((option) => option.value === model?.value)?.label : (placeholder ?? 'Select an option')
          }}
          <ChevronsUpDownIcon class="ml-2 h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent class="w-[200px] p-0" align="start" side="bottom">
        <Command>
          <CommandInput :placeholder="placeholder ?? 'Search an option'" />
          <CommandList>
            <CommandEmpty>No framework found.</CommandEmpty>
            <CommandGroup>
              <CommandItem
                v-for="option in options"
                :key="option.value"
                :value="option.value"
                @select="
                  () => {
                    model = model?.value === option.value ? undefined : option
                    open = false
                  }
                "
              >
                {{ option.label }}
                <CheckIcon :class="cn('mr-2 h-4 w-4', model?.value === option.value ? 'opacity-100' : 'opacity-0')" />
              </CommandItem>
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
    <template #help>
      <slot name="help" />
    </template>
  </Base>
</template>
<script setup lang="ts">
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@shared:ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@shared:ui/popover'
import { CheckIcon, ChevronsUpDownIcon } from 'lucide-vue-next'
import { ref } from 'vue'
import { useId } from 'vue'

import type { BaseInputProps } from './Base.vue'
import Base from './Base.vue'

const id = useId()
const open = ref(false)

export type Option = {
  label: string
  value: string
}

type Props = Omit<BaseInputProps, 'id'> & {
  options: Option[]
  placeholder?: string
}

defineProps<Props>()
defineOptions({ inheritAttrs: false })

const model = defineModel<Option | undefined>()
</script>
