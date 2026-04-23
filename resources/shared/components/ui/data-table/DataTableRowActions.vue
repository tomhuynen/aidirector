<template>
  <!-- Dropdown mode (when asDropdown is explicitly true) -->
  <DropdownMenu v-if="column.asDropdown && visibleActions.length">
    <DropdownMenuTrigger as-child>
      <Button variant="ghost" size="icon" class="size-8">
        <MoreHorizontal class="size-4" />
        <span class="sr-only">{{ trans('Open menu') }}</span>
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-48">
      <template v-for="action in visibleActions" :key="action.id">
        <DropdownMenuItem
          :class="{ 'text-destructive focus:text-destructive': action.variant === 'danger' }"
          :disabled="!action.authorized || !!action.bindings?.disabled"
          @select="handleActionClick(action)"
        >
          <component :is="getIconComponent(action.icon)" v-if="action.icon" class="mr-2 size-4" />
          {{ action.label }}
        </DropdownMenuItem>
      </template>
    </DropdownMenuContent>
  </DropdownMenu>

  <!-- Inline buttons mode (DEFAULT) -->
  <div v-else-if="visibleActions.length" class="flex items-center justify-end gap-1">
    <Button
      v-for="action in visibleActions"
      :key="action.id"
      :variant="action.variant === 'danger' ? 'destructive' : 'ghost'"
      size="sm"
      :disabled="!action.authorized || !!action.bindings?.disabled"
      @click="handleActionClick(action)"
    >
      <component
        :is="getIconComponent(action.icon)"
        v-if="action.icon"
        class="size-4"
        :class="{ 'mr-1': action.label }"
      />
      {{ action.label }}
    </Button>
  </div>

  <!-- Confirmation Dialog -->
  <Dialog v-model:open="confirmDialogOpen">
    <DialogContent :show-close-button="false">
      <DialogHeader>
        <DialogTitle>{{ pendingAction?.confirmationTitle ?? trans('Confirm Action') }}</DialogTitle>
        <DialogDescription v-if="pendingAction?.confirmationMessage">
          {{ pendingAction.confirmationMessage }}
        </DialogDescription>
      </DialogHeader>
      <DialogFooter>
        <Button variant="outline" @click="confirmDialogOpen = false">
          {{ pendingAction?.confirmationCancelButton ?? trans('Cancel') }}
        </Button>
        <Button :variant="pendingAction?.variant === 'danger' ? 'destructive' : 'default'" @click="confirmAction">
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
import { router } from '@inertiajs/vue3'
import { visitModal } from '@inertiaui/modal-vue'
import { getActionForItem } from '@inertiaui/table-vue'
import { Button } from '@shared:ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@shared:ui/dialog'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@shared:ui/dropdown-menu'
import { trans } from 'laravel-vue-i18n'
import { MoreHorizontal } from 'lucide-vue-next'
import { computed, ref } from 'vue'

import { useIcons } from './composables/useIcons'
import type {
  ActionItem,
  ActionResult,
  RowActionItem,
  TableAction,
  TableColumn,
  TableItem,
  TableResource,
  UseActionsReturn,
} from './types'

const { getIconComponent } = useIcons()

const props = defineProps<{
  item: TableItem
  column: TableColumn
  resource: TableResource
  actions: UseActionsReturn
}>()

const emit = defineEmits<{
  'custom-action': [payload: { action: TableAction; keys: (string | number)[]; onFinish?: () => void }]
}>()

// Dialog state
const confirmDialogOpen = ref(false)
const actionFailed = ref(false)
const pendingAction = ref<(ActionItem & { _index: number }) | null>(null)

const visibleActions = computed(() => {
  const allActions = props.resource.actions ?? []
  const itemActions = props.item._actions ?? {}

  return allActions
    .map((action, index) => {
      // Skip non-row actions
      if (!action.asRowAction) return null

      const itemAction = itemActions[index]
      const resolved = getActionForItem(action, itemAction) as ActionItem
      return { ...action, ...resolved, _index: index }
    })
    .filter((action): action is ActionItem & { _index: number } => action !== null && action.isVisible)
})

const handleActionClick = (action: ActionItem & { _index: number }) => {
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

const executeAction = async (action: ActionItem & { _index: number }) => {
  const itemActions = props.item._actions ?? {}
  const itemAction: RowActionItem | string | undefined = itemActions[action._index]

  // Download actions
  const asDownload = action.asDownload || (typeof itemAction === 'object' ? itemAction?.asDownload : undefined)
  if (asDownload) {
    const url = typeof itemAction === 'string' ? itemAction : itemAction?.url
    if (url) {
      const link = document.createElement('a')
      link.href = url
      link.download = typeof asDownload === 'string' ? asDownload : ''
      link.click()
      return
    }
  }

  // Link actions navigate instead of POST
  if (action.isLink && itemAction) {
    const url = typeof itemAction === 'string' ? itemAction : itemAction.url
    const modal = typeof itemAction === 'object' ? itemAction.modal : undefined

    if (url) {
      if (modal) {
        visitModal(url, modal === true ? {} : modal)
      } else {
        router.visit(url)
      }
      return
    }
  }

  // Custom actions emit event instead of POSTing
  if (action.isCustom) {
    try {
      const keys = props.item._primary_key !== undefined ? [props.item._primary_key] : []
      const result = (await props.actions.performAction(action, keys)) as ActionResult
      emit('custom-action', {
        action,
        keys: result.keys,
        onFinish: result.onFinish,
      })
    } catch {
      actionFailed.value = true
    }
    return
  }

  // Non-link actions use performAction
  try {
    const keys = props.item._primary_key !== undefined ? [props.item._primary_key] : []
    await props.actions.performAction(action, keys)
  } catch {
    actionFailed.value = true
  }
}
</script>
