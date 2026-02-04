import { resolve } from 'node:path'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'
const customElementTags: string[] = []

export default defineConfig(({ command }) => {
  const base = command === 'serve' ? undefined : '/assets/public/'

  return {
    plugins: [
      laravel({
        hotFile: 'public/hot-public',
        buildDirectory: base,
        input: [resolve(__dirname, 'resources/public/ts/app.ts')],
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
      tailwindcss(),
    ],
    build: {
      outDir: resolve(__dirname, 'public/assets/public/'),
      assetsDir: 'v',
      manifest: 'manifest.json',
      sourcemap: true,
      rollupOptions: {
        output: {
          manualChunks(id: string) {
            if (id.includes('@inertiajs') || id.includes('nprogress')) {
              return 'inertia'
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
        '@public:css': resolve(__dirname, 'resources/public/css/'),
        '@public:components': resolve(__dirname, 'resources/public/ts/components/'),
        '@public': resolve(__dirname, 'resources/public/'),
        '@shared': resolve(__dirname, 'resources/shared/'),
        '@shared:ui': resolve(__dirname, 'resources/shared/components/ui/'),
        '@wayfinder': resolve(__dirname, 'resources/shared/wayfinder/'),
      },
    },
  }
})
