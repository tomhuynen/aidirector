import '@admin:css/style.css'
import '@inertiaui/table-vue/style.css'
import '@github/relative-time-element'

import { initializeTheme } from '@admin:composables/appearance'
import { createInertiaApp } from '@inertiajs/vue3'
import { initFromPageProps, Modal, ModalLink, ModalRoot } from '@inertiaui/modal-vue'
import { createApp, type DefineComponent, h } from 'vue'

import { useIcons } from './composables/icons'
import { registerPlugins } from './shared/plugins'

const appName = import.meta.env.VITE_APP_NAME

createInertiaApp({
  title: (title) => `${title} - ${appName}`,
  resolve: (name) => {
    const pages = import.meta.glob('./pages/**/*.vue', { eager: true })

    return pages[`./pages/${name}.vue`] as DefineComponent
  },
  setup: ({ el, App, props, plugin }) => {
    initFromPageProps(props)

    const app = createApp({
      name: 'AppAdmin',
      render: () => h(ModalRoot, () => h(App, props)),
    })

    registerPlugins(app)

    app.component('Modal', Modal)
    app.component('ModalLink', ModalLink)

    app.use(plugin).mount(el)
  },
})

initializeTheme()
useIcons()
