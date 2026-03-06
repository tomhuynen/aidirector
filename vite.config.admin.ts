import { resolve } from 'node:path'

import { wayfinder } from '@laravel/vite-plugin-wayfinder'
import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

import { typeGenerator } from './resources/vite/plugins/type-generator'

const customElementTags = ['relative-time']

export default defineConfig(({ command }) => {
  const base = command === 'serve' ? undefined : '/assets/admin/'

  return {
    plugins: [
      laravel({
        hotFile: 'public/hot-admin',
        buildDirectory: base,
        input: [resolve(__dirname, 'resources/admin/ts/app.ts')],
      }),
      vue({
        template: {
          compilerOptions: {
            isCustomElement: (tag) => customElementTags.includes(tag),
          },
          transformAssetUrls: {
            base: null,
            includeAbsolute: false,
          },
        },
      }),
      wayfinder({
        path: 'resources/shared/wayfinder',
        actions: false,
      }),
      tailwindcss(),
      typeGenerator({
        outputPath: resolve(__dirname, 'resources/admin/ts/types/schema.d.ts'),
        apiName: 'admin',
        typeImports: {
          TableResource: { type: 'TableResource', from: '@shared:ui/data-table' },
        },
      }),
    ],
    build: {
      outDir: resolve(__dirname, 'public/assets/admin/'),
      assetsDir: 'v',
      manifest: 'manifest.json',
      sourcemap: true,
      rollupOptions: {
        treeshake: 'smallest',
        output: {
          manualChunks(id: string) {
            if (id.includes('lucide-vue-next')) {
              return 'lucide-vue-next'
            }

            if (id.includes('lodash')) {
              return 'lodash'
            }

            if (id.includes('floating')) {
              return 'floating'
            }

            if (id.includes('lightweight-charts')) {
              return 'lightweight-charts'
            }

            if (id.includes('radix')) {
              return 'radix'
            }

            if (id.includes('@inertiajs') || id.includes('nprogress')) {
              return 'inertia'
            }

            if (
              id.includes('tus-js') ||
              id.includes('querystringify') ||
              id.includes('js-base64') ||
              id.includes('url-parse')
            ) {
              return 'tus'
            }

            // vue & vueuse
            if (id.includes('@vue')) {
              return 'vue'
            }
          },
        },
      },
    },
    resolve: {
      alias: {
        '@admin:css': resolve(__dirname, 'resources/admin/css/'),
        '@admin:components': resolve(__dirname, 'resources/admin/ts/components/'),
        '@admin:composables': resolve(__dirname, 'resources/admin/ts/composables/'),
        '@admin:layouts': resolve(__dirname, 'resources/admin/ts/layouts/'),
        '@admin:shared': resolve(__dirname, 'resources/admin/ts/shared/'),
        '@admin:types': resolve(__dirname, 'resources/admin/ts/types/'),
        '@admin': resolve(__dirname, 'resources/admin/'),
        '@shared': resolve(__dirname, 'resources/shared/'),
        '@shared:ui': resolve(__dirname, 'resources/shared/components/ui/'),
        '@routes': resolve(__dirname, 'resources/shared/wayfinder/routes/'),
      },
    },
  }
})
