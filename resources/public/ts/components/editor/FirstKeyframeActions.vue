<template>
  <div class="flex shrink-0 items-center gap-2">
    <Button type="button" variant="outline" :disabled="disabled || more.processing" @click="askMore">
      <RefreshCw class="size-4" />
      {{ $t('More options') }}
    </Button>
  </div>
</template>
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { Button } from '@shared:ui/button'
import { RefreshCw } from 'lucide-vue-next'

/** Under the options of keyframe 1: draw another round to choose from. */
const props = defineProps<{
  moreUrl: string
  disabled?: boolean
}>()

const more = useForm({})

const askMore = () => more.post(props.moreUrl, { preserveScroll: true })
</script>
