import type { App } from 'vue'

import { i18nVue, options } from './i18n'

export const registerPlugins = (app: App) => {
  app.use(i18nVue, options)
}
