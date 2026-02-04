import { http } from '@admin:shared/http'
import { router } from '@inertiajs/vue3'
import { ref } from 'vue'

import type { PostResponse, Widget } from '../types/utils'

export function useWidget(identifier: string) {
  const isProcessingAction = ref(false)

  const executeAction = (action: Widget['actions'][number], params: string[] = []) => {
    isProcessingAction.value = true

    return http
      .post<PostResponse<'/admin/widgets/action/execute'>>('/admin/widgets/action/execute', {
        identifier: identifier,
        action: action.method,
        params: params,
      })
      .finally(() => {
        router.reload({
          only: ['widgets'],
          onFinish: () => {
            isProcessingAction.value = false
          },
        })
      })
  }

  return {
    executeAction,
    isProcessingAction,
  }
}
