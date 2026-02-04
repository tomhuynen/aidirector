import { useDebounceFn } from '@vueuse/core'
import { type Ref, ref, watch } from 'vue'
import { toast } from 'vue-sonner'

import { http } from '../shared/http'
import { $t } from '../shared/i18n'
import type { GetResponse, SearchResult } from '../types/utils'

type Options<T> = {
  entity: string
  initialValue?: T[]
  perPage?: number
  immediate?: boolean
}

export function useSearchable<T = SearchResult>(options: Options<T>) {
  const items = ref<T[]>(options.initialValue ?? []) as Ref<T[]>
  const search = ref('')
  const loading = ref(false)
  const hasMore = ref(true)
  const cursor = ref<string | null>(null)

  const fetch = async (append = false) => {
    loading.value = true

    http
      .get<GetResponse<'/admin/search/{entity}'>>(`/admin/search/${options.entity}`, {
        params: {
          ...(search.value ? { q: search.value } : {}),
          cursor: append ? cursor.value : undefined,
        },
      })
      .then((response) => {
        const data = response.data.data as T[]
        items.value = append ? [...items.value, ...data] : data
        cursor.value = response.data.meta.next_cursor ?? null
        hasMore.value = !!response.data.meta.next_cursor
      })
      .catch(() => {
        toast.error($t('An error occurred while fetching the data.'))
      })
      .finally(() => {
        loading.value = false
      })
  }

  const fetchSingle = (id: string | number): Promise<T | null> => {
    loading.value = true

    return http
      .get<GetResponse<'/admin/search/{entity}/{id}'>>(`/admin/search/${options.entity}/${id}`)
      .then((response) => {
        return response.data as T
      })
      .catch(() => {
        toast.error($t('An error occurred while fetching the data.'))
        return null
      })
      .finally(() => {
        loading.value = false
      })
  }

  const debouncedFetch = useDebounceFn(() => fetch(false), 300)

  watch(search, () => {
    cursor.value = null
    debouncedFetch()
  })

  const loadMore = () => {
    if (hasMore.value && !loading.value) {
      fetch(true)
    }
  }

  if (options.immediate) {
    fetch(false)
  }

  return {
    items,
    search,
    loading,
    hasMore,
    loadMore,
    fetchSingle,
    refresh: () => fetch(false),
  }
}
