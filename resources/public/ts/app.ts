import '@public:css/style.css'

import { createInertiaApp } from '@inertiajs/vue3'
import type { DefineComponent } from 'vue'
import { createApp, h } from 'vue'

const appName = import.meta.env.VITE_APP_NAME

createInertiaApp({
  title: (title: string) => `${title} - ${appName}`,
  resolve: (name: string) => {
    const pages = import.meta.glob('./pages/**/*.vue', { eager: true })

    return pages[`./pages/${name}.vue`] as DefineComponent
  },
  setup: ({ el, App, props, plugin }) => {
    const app = createApp({
      name: 'AppPublic',
      render: () => h(App, props),
    })

    app.use(plugin).mount(el)
  },
})
