import { cachedHttp } from '@admin:shared/http'
import type { ComputedRef } from 'vue'
import { computed, reactive } from 'vue'

const settings = reactive<Record<string, unknown>>({})

const getSetting = (name: string) => {
  return computed(() => settings[name])
}

export function useSetting<T>(name: string) {
  useSettings()

  return getSetting(name) as ComputedRef<T>
}

export function useSettings() {
  cachedHttp.get('/admin/config/settings').then((response) => {
    const data = response.data

    Object.keys(data).forEach((key) => {
      settings[key] = data[key]
    })
  })

  return {
    settings,
    getSetting,
  }
}
