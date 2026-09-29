import '@public:css/style.css'

import { createInertiaApp } from '@inertiajs/vue3'
import type { DefineComponent } from 'vue'
import { createApp, h } from 'vue'

import { registerPlugins } from './shared/plugins'

const appName = import.meta.env.VITE_APP_NAME

createInertiaApp({
  title: (title: string) => (title ? `${title} - ${appName}` : appName),
  resolve: (name: string) => {
    const pages = import.meta.glob('./pages/**/*.vue', { eager: true })

    return pages[`./pages/${name}.vue`] as DefineComponent
  },
  setup: ({ el, App, props, plugin }) => {
    const app = createApp({
      name: 'AppPublic',
      render: () => h(App, props),
    })

    registerPlugins(app)

    app.use(plugin).mount(el)
  },
})
