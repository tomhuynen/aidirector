import { usePage as useInertiaPage } from '@inertiajs/vue3'
import { computed } from 'vue'

import type { PageProps } from '../types/shared'

export function usePage() {
  const page = useInertiaPage<PageProps>()

  const account = computed(() => page.props.account)
  const app = computed(() => page.props.app)

  return {
    account,
    app,
  }
}
