import type { PageProps } from '@admin:types/shared'
import { usePage as useInertiaPage } from '@inertiajs/vue3'
import { computed } from 'vue'

export function usePage() {
  const inertiaPage = computed(() => useInertiaPage<PageProps>().props)

  return {
    account: computed(() => inertiaPage.value.app.account),
    env: computed(() => inertiaPage.value.app.env),
    route: computed(() => inertiaPage.value.app.route),
    title: computed(() => inertiaPage.value.app.title),
    navigation: computed(() => inertiaPage.value.app.navigation),
    breadcrumbs: computed(() => inertiaPage.value.page?.breadcrumbs ?? []),
    pageActions: computed(() => inertiaPage.value.page?.actions ?? []),
  }
}
