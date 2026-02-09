<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="outline" size="sm" class="h-8">
        <Bookmark class="mr-2 size-4" :class="{ 'text-emerald-500': hasActiveView }" />
        {{ trans('Views') }}
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-48">
      <template v-if="views.length">
        <DropdownMenuLabel>{{ trans('Saved views') }}</DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem
          v-for="view in views"
          :key="view.id"
          class="group justify-between"
          @select="emit('view-selected', view)"
        >
          <span class="flex items-center gap-2">
            <Check v-if="isActiveView(view)" class="size-4 text-emerald-500" />
            <span v-else class="size-4" />
            {{ view.title }}
          </span>
          <button type="button" class="opacity-0 group-hover:opacity-100" @click.stop="deleteView(view)">
            <X class="size-3.5 text-muted-foreground hover:text-destructive" />
          </button>
        </DropdownMenuItem>
        <DropdownMenuSeparator />
      </template>
      <DropdownMenuItem @select="openSaveDialog">
        <Plus class="mr-2 size-4" />
        {{ trans('Save view') }}
      </DropdownMenuItem>
    </DropdownMenuContent>
  </DropdownMenu>

  <!-- Save View Dialog -->
  <Dialog v-model:open="dialogOpen">
    <DialogContent :show-close-button="false">
      <DialogHeader>
        <DialogTitle>{{ trans('Save view') }}</DialogTitle>
        <DialogDescription>{{ trans('Enter a name for your view') }}</DialogDescription>
      </DialogHeader>
      <Input v-model="viewTitle" :placeholder="trans('View name')" @keyup.enter="storeView" />
      <DialogFooter>
        <Button variant="outline" @click="closeDialog">{{ trans('Cancel') }}</Button>
        <Button :disabled="!viewTitle.trim()" @click="storeView">{{ trans('Save view') }}</Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>
</template>

<script setup lang="ts">
import { Button } from '@shared:ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@shared:ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@shared:ui/dropdown-menu'
import { Input } from '@shared:ui/input'
import axios from 'axios'
import { trans } from 'laravel-vue-i18n'
import { Bookmark, Check, Plus, X } from 'lucide-vue-next'
import { computed, ref } from 'vue'

import type { TableState, TableView, TableViews } from './types'

const props = defineProps<{
  views: TableViews
  currentState: TableState
}>()

const emit = defineEmits<{
  'view-selected': [view: TableView]
}>()

const views = ref<TableView[]>(props.views.data || [])
const dialogOpen = ref(false)
const viewTitle = ref('')

const isActiveView = (view: TableView): boolean => JSON.stringify(props.currentState) === JSON.stringify(view.state)

const hasActiveView = computed(() => views.value.some((view) => isActiveView(view)))

const openSaveDialog = () => {
  dialogOpen.value = true
}

const closeDialog = () => {
  dialogOpen.value = false
  viewTitle.value = ''
}

const storeView = async () => {
  if (!viewTitle.value.trim()) return

  const response = await axios.post(props.views.storeUrl, {
    title: viewTitle.value,
    query: props.views.query,
  })

  views.value = response.data.data
  closeDialog()
}

const deleteView = async (view: TableView) => {
  await axios.delete(view.deleteUrl)
  views.value = views.value.filter((v) => v.id !== view.id)
}
</script>
