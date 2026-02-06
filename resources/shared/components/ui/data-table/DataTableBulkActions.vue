<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <Button variant="outline" size="sm" class="h-8" :disabled="!hasSelection">
        <Settings2 class="mr-2 size-4" />
        {{ trans('Actions') }}
        <span v-if="hasSelection" class="ml-1 rounded-full bg-primary px-1.5 text-xs text-primary-foreground">
          {{ selectionCount }}
        </span>
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-48">
      <DropdownMenuLabel>{{ trans('Bulk actions') }}</DropdownMenuLabel>
      <DropdownMenuSeparator />
      <template v-for="action in bulkActions" :key="action.key">
        <DropdownMenuItem v-if="action.authorized" :disabled="!hasSelection" @select="handleActionClick(action)">
          {{ action.label }}
        </DropdownMenuItem>
      </template>
      <template v-if="resource.exports?.length">
        <DropdownMenuSeparator />
        <DropdownMenuLabel>{{ trans('Export') }}</DropdownMenuLabel>
        <DropdownMenuItem
          v-for="exp in resource.exports"
          :key="exp.key"
          :disabled="exp.limitToSelectedRows && !hasSelection"
          @select="handleExport(exp)"
        >
          {{ exp.label }}
        </DropdownMenuItem>
      </template>
    </DropdownMenuContent>
  </DropdownMenu>

  <!-- Confirmation Dialog -->
  <Dialog v-model:open="confirmDialogOpen">
    <DialogContent :show-close-button="false">
      <DialogHeader>
        <DialogTitle>{{ pendingAction?.confirmationTitle ?? trans('Confirm Action') }}</DialogTitle>
        <DialogDescription v-if="pendingAction?.confirmationMessage">
          {{ pendingAction.confirmationMessage }}
        </DialogDescription>
        <DialogDescription v-else>
          {{ trans('This will affect :count item(s).', { count: String(selectionCount) }) }}
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" @click="confirmDialogOpen = false">
          {{ pendingAction?.confirmationCancelButton ?? trans('Cancel') }}
        </Button>
        <Button variant="destructive" @click="confirmAction">
          {{ pendingAction?.confirmationConfirmButton ?? trans('Confirm') }}
        </Button>
      </DialogFooter>
    </DialogContent>
  </Dialog>

  <!-- Failed Action Dialog -->
  <Dialog v-model:open="actionFailed">
    <DialogContent :show-close-button="false">
      <DialogHeader>
        <DialogTitle>{{ trans('Action Failed') }}</DialogTitle>
        <DialogDescription>{{
          trans('Something went wrong while performing the action. Please try again.')
        }}</DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="destructive" @click="actionFailed = false">{{ trans('OK') }}</Button>
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
import { trans } from 'laravel-vue-i18n'
import { Settings2 } from 'lucide-vue-next'
import { computed, ref } from 'vue'

import type { TableAction, TableExport, TableResource, UseActionsReturn } from './types'

const props = defineProps<{
  resource: TableResource
  actions: UseActionsReturn
}>()

const emit = defineEmits<{
  'action-success': [result: unknown]
  'custom-action': [action: unknown]
}>()

// Dialog state
const confirmDialogOpen = ref(false)
const actionFailed = ref(false)
const pendingAction = ref<TableAction | null>(null)

const bulkActions = computed(() => props.resource.actions?.filter((a) => a.asBulkAction) ?? [])

const hasSelection = computed(
  () => props.actions.allItemsAreSelected.value || props.actions.selectedItems.value.length > 0,
)

const selectionCount = computed(() =>
  props.actions.allItemsAreSelected.value
    ? (props.resource.results.total ?? 'All')
    : props.actions.selectedItems.value.length,
)

const handleActionClick = (action: TableAction) => {
  if (action.confirmationRequired) {
    pendingAction.value = action
    confirmDialogOpen.value = true
  } else {
    executeAction(action)
  }
}

const confirmAction = () => {
  if (pendingAction.value) {
    executeAction(pendingAction.value)
  }
  confirmDialogOpen.value = false
  pendingAction.value = null
}

const executeAction = async (action: TableAction) => {
  try {
    const result = await props.actions.performAction(action)
    if (action.isCustom) {
      emit('custom-action', { action, ...result })
    } else {
      emit('action-success', result)
    }
  } catch {
    actionFailed.value = true
  }
}

const handleExport = async (exp: TableExport) => {
  try {
    await props.actions.performAsyncExport(exp)
  } catch {
    actionFailed.value = true
  }
}
</script>
