<template>
  <aside
    :class="
      cn(
        'flex shrink-0 flex-col border-r border-border transition-[width] duration-200',
        collapsed ? 'w-14 items-center gap-4 py-4' : 'w-[22rem] gap-6 overflow-y-auto p-6',
      )
    "
  >
    <template v-if="collapsed">
      <Button
        type="button"
        variant="ghost"
        size="icon-sm"
        :aria-label="$t('Show the storyline')"
        :title="$t('Show the storyline')"
        @click="toggle"
      >
        <PanelLeftOpen class="size-4" />
      </Button>
      <button
        type="button"
        class="min-h-0 truncate text-sm font-semibold text-muted-foreground [writing-mode:vertical-rl] hover:text-foreground"
        @click="toggle"
      >
        {{ shot.title }}
      </button>
    </template>

    <template v-else>
      <div class="flex items-start justify-between gap-3">
        <h2 class="text-2xl leading-tight font-semibold text-balance">{{ shot.title }}</h2>
        <div class="flex shrink-0 items-center gap-1">
          <ConfirmDelete
            :action="shot.links?.storylineReopen ?? '#'"
            :title="$t('Back to the storylines?')"
            :description="
              $t(
                'You choose a storyline for this shot again. Its keyframes, their images and the video are removed. The cast and sets stay in the project.',
              )
            "
            :confirm-label="$t('Back to storylines')"
          >
            <template #trigger>
              <Button type="button" variant="outline" size="sm">
                <Pencil class="size-4" />
                {{ $t('Edit') }}
              </Button>
            </template>
          </ConfirmDelete>
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            :aria-label="$t('Hide the storyline')"
            :title="$t('Hide the storyline')"
            @click="toggle"
          >
            <PanelLeftClose class="size-4" />
          </Button>
        </div>
      </div>

      <section v-if="storyline" class="space-y-2">
        <h3 class="flex items-center gap-2 font-semibold">
          <ListOrdered class="size-4 text-muted-foreground" />
          {{ $t('Storyline') }}
        </h3>
        <p class="text-[15px] leading-relaxed text-muted-foreground">{{ storyline.storyline }}</p>
      </section>

      <section class="space-y-2 border-t border-border pt-6">
        <h3 class="flex items-center gap-2 font-semibold">
          <Target class="size-4 text-muted-foreground" />
          {{ $t('Takeaway') }}
        </h3>
        <p class="text-[15px] leading-relaxed">{{ shot.takeaway }}</p>
      </section>
    </template>
  </aside>
</template>
<script setup lang="ts">
import { $t } from '@public/ts/shared/i18n'
import type { Inertia } from '@public/ts/types/utils'
import ConfirmDelete from '@public:components/ConfirmDelete.vue'
import { cn } from '@shared/lib/utils'
import { Button } from '@shared:ui/button'
import { ListOrdered, PanelLeftClose, PanelLeftOpen, Pencil, Target } from 'lucide-vue-next'
import { ref } from 'vue'

defineProps<{
  shot: Inertia.Pages.Shots.View['shot']
  storyline: { title: string; storyline: string } | null
}>()

/** Remembered per browser, so the column stays folded while moving between shots. */
const STORAGE_KEY = 'aidirector.shot-details.collapsed'

const read = (): boolean => {
  try {
    return window.localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

const collapsed = ref(read())

const toggle = () => {
  collapsed.value = !collapsed.value

  try {
    window.localStorage.setItem(STORAGE_KEY, collapsed.value ? '1' : '0')
  } catch {
    // Storage can be blocked; the column still folds for this page.
  }
}
</script>
