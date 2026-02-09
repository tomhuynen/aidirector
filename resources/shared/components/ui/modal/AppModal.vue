<template>
  <HeadlessModal
    ref="modal"
    v-bind="$attrs"
    v-slot="slotProps"
    :slideover="slideover"
    :close-button="closeButton"
    :close-explicitly="closeExplicitly"
    :max-width="maxWidth"
    :position="position"
    @success="$emit('success')"
    @close="$emit('close')"
    @focus="$emit('focus')"
    @blur="$emit('blur')"
  >
    <!-- Slideover mode → Sheet -->
    <Sheet v-if="slotProps.config?.slideover" :open="slotProps.isOpen" @update:open="slotProps.setOpen">
      <SheetContent
        :side="slotProps.config.position === 'left' ? 'left' : 'right'"
        :class="[getMaxWidthClass(slotProps.config.maxWidth), { 'blur-sm': !slotProps.onTopOfStack }]"
        @escape-key-down="slotProps.config?.closeExplicitly && $event.preventDefault()"
        @interact-outside="slotProps.config?.closeExplicitly && $event.preventDefault()"
        @close-auto-focus="slotProps.afterLeave"
      >
        <VisuallyHidden as-child>
          <SheetTitle />
        </VisuallyHidden>
        <VisuallyHidden as-child>
          <SheetDescription />
        </VisuallyHidden>
        <slot v-bind="{ ...slotProps, navigate: (url: string) => navigate(slotProps, url) }" />
      </SheetContent>
    </Sheet>

    <!-- Modal mode → Dialog -->
    <Dialog v-else :open="slotProps.isOpen" @update:open="slotProps.setOpen">
      <DialogContent
        :show-close-button="slotProps.config?.closeButton ?? true"
        :class="[getMaxWidthClass(slotProps.config?.maxWidth), { 'blur-sm': !slotProps.onTopOfStack }]"
        @escape-key-down="slotProps.config?.closeExplicitly && $event.preventDefault()"
        @interact-outside="slotProps.config?.closeExplicitly && $event.preventDefault()"
        @close-auto-focus="slotProps.afterLeave"
      >
        <VisuallyHidden as-child>
          <DialogTitle />
        </VisuallyHidden>
        <VisuallyHidden as-child>
          <DialogDescription />
        </VisuallyHidden>
        <slot v-bind="{ ...slotProps, navigate: (url: string) => navigate(slotProps, url) }" />
      </DialogContent>
    </Dialog>
  </HeadlessModal>
</template>

<script setup lang="ts">
import { HeadlessModal } from '@inertiaui/modal-vue'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@shared:ui/dialog'
import { Sheet, SheetContent, SheetDescription, SheetTitle } from '@shared:ui/sheet'
import { VisuallyHidden } from 'reka-ui'
import { ref, toRaw } from 'vue'

defineOptions({
  inheritAttrs: false,
})

defineProps<{
  slideover?: boolean
  closeButton?: boolean
  closeExplicitly?: boolean
  maxWidth?: string
  position?: string
}>()

defineEmits<{
  success: []
  close: []
  focus: []
  blur: []
}>()

const modal = ref<InstanceType<typeof HeadlessModal> | null>(null)

const maxWidthClasses: Record<string, string> = {
  'sm': 'sm:max-w-sm',
  'md': 'sm:max-w-md',
  'lg': 'sm:max-w-md md:max-w-lg',
  'xl': 'sm:max-w-md md:max-w-xl',
  '2xl': 'sm:max-w-md md:max-w-xl lg:max-w-2xl',
  '3xl': 'sm:max-w-md md:max-w-xl lg:max-w-3xl',
  '4xl': 'sm:max-w-md md:max-w-xl lg:max-w-3xl xl:max-w-4xl',
  '5xl': 'sm:max-w-md md:max-w-xl lg:max-w-3xl xl:max-w-5xl',
  '6xl': 'sm:max-w-md md:max-w-xl lg:max-w-3xl xl:max-w-5xl 2xl:max-w-6xl',
  '7xl': 'sm:max-w-md md:max-w-xl lg:max-w-3xl xl:max-w-5xl 2xl:max-w-7xl',
}

const navigate = (slotProps: Record<string, unknown>, url: string) => {
  const raw = toRaw(slotProps.modalContext) as { response: { url: string }; reload: () => void }
  raw.response.url = url
  raw.reload()
}

const getMaxWidthClass = (maxWidth?: string): string => {
  if (!maxWidth) return ''
  return maxWidthClasses[maxWidth] ?? ''
}

defineExpose({
  afterLeave: () => modal.value?.afterLeave(),
  close: () => modal.value?.close(),
  emit: (...args: unknown[]) => modal.value?.emit(...args),
  getChildModal: () => modal.value?.getChildModal(),
  getParentModal: () => modal.value?.getParentModal(),
  reload: (...args: unknown[]) => modal.value?.reload(...args),
  setOpen: (...args: unknown[]) => modal.value?.setOpen(...args),

  get config() {
    return modal.value?.config
  },
  get id() {
    return modal.value?.id
  },
  get index() {
    return modal.value?.index
  },
  get isOpen() {
    return modal.value?.isOpen
  },
  get modalContext() {
    return modal.value?.modalContext
  },
  get onTopOfStack() {
    return modal.value?.onTopOfStack
  },
  get shouldRender() {
    return modal.value?.shouldRender
  },
})
</script>
