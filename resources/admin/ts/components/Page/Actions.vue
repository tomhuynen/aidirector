<template>
  <aside ref="containerRef" class="relative ml-auto flex min-w-0 flex-1 items-center justify-end">
    <!-- Hidden copy used to measure whether the inline buttons fit -->
    <div
      ref="measureRef"
      aria-hidden="true"
      class="pointer-events-none invisible absolute right-0 flex items-center gap-2"
    >
      <PageActionsContext inline>
        <slot name="prepend" />
        <slot />
        <slot name="append" />
      </PageActionsContext>
    </div>

    <!-- Inline buttons when there is enough room -->
    <div v-if="fitsInline" class="flex items-center gap-2">
      <PageActionsContext inline>
        <slot name="prepend" />
        <slot />
        <slot name="append" />
      </PageActionsContext>
    </div>

    <!-- Dropdown fallback when space is limited -->
    <DropdownMenu v-else>
      <DropdownMenuTrigger as-child>
        <Button variant="ghost" size="icon" class="h-7 w-7 data-[state=open]:bg-accent">
          <MoreHorizontal class="h-4 w-4" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" class="w-56">
        <DropdownMenuLabel>{{ $t('Actions') }}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuGroup>
          <PageActionsContext :inline="false">
            <!-- Prepend slot for client-side actions -->
            <slot name="prepend" />

            <!-- Server-side actions -->
            <slot />

            <!-- Append slot for client-side actions -->
            <slot name="append" />
          </PageActionsContext>
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  </aside>
</template>

<script setup lang="ts">
import PageActionsContext from '@admin:components/Page/ActionsContext.vue'
import { $t } from '@admin:shared/i18n'
import { Button } from '@shared:ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@shared:ui/dropdown-menu'
import { useElementSize } from '@vueuse/core'
import { MoreHorizontal } from 'lucide-vue-next'
import { computed, useTemplateRef } from 'vue'

const containerRef = useTemplateRef<HTMLElement>('containerRef')
const measureRef = useTemplateRef<HTMLElement>('measureRef')

const { width: containerWidth } = useElementSize(containerRef)
const { width: measureWidth } = useElementSize(measureRef)

const fitsInline = computed(() => measureWidth.value > 0 && measureWidth.value <= containerWidth.value)
</script>
