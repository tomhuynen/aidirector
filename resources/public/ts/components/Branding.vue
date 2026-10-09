<template>
  <section class="space-y-4">
    <div class="space-y-1">
      <h2 class="text-xl font-semibold">{{ $t('Branding') }}</h2>
      <p class="text-sm text-muted-foreground">
        {{
          $t(
            'Logos of the company. They are the only text the images may show, always drawn from these pictures. Name the file after the brand, such as Damen.png.',
          )
        }}
      </p>
    </div>

    <ul class="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
      <li v-for="logo in logos" :key="logo.id" class="group min-w-0 space-y-1.5">
        <span
          class="relative flex aspect-square items-center justify-center overflow-hidden rounded-xl border border-border bg-white p-4"
        >
          <img :src="logo.imageUrl" :alt="logo.name" class="max-h-full max-w-full object-contain" loading="lazy" />
          <button
            type="button"
            class="absolute top-1.5 right-1.5 hidden size-7 items-center justify-center rounded-full bg-background/80 text-destructive backdrop-blur-sm group-hover:flex focus-visible:flex"
            :aria-label="$t('Remove :name', { name: logo.name })"
            :title="$t('Remove :name', { name: logo.name })"
            :disabled="removing === logo.id"
            @click="remove(logo)"
          >
            <LoaderCircle v-if="removing === logo.id" class="size-3.5 animate-spin" />
            <Trash2 v-else class="size-3.5" />
          </button>
        </span>
        <span class="block truncate text-sm" :title="logo.name">{{ logo.name }}</span>
      </li>

      <li>
        <label
          for="branding-logo"
          :class="
            cn(
              'flex aspect-square w-full cursor-pointer flex-col items-center justify-center gap-1.5 rounded-xl border border-dashed border-border bg-card/60 text-muted-foreground transition-colors hover:border-muted-foreground/60 hover:text-foreground',
              busy && 'pointer-events-none',
            )
          "
          :title="$t('Upload a logo')"
        >
          <LoaderCircle v-if="busy" class="size-6 animate-spin text-signal" />
          <template v-else>
            <ImageUp class="size-6" />
            <span class="text-xs font-medium">{{ $t('Upload a logo') }}</span>
          </template>
        </label>
        <input
          id="branding-logo"
          type="file"
          accept="image/png,image/jpeg,image/webp"
          multiple
          class="sr-only"
          @change="choose"
        />
      </li>
    </ul>
    <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
  </section>
</template>

<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { useUploads } from '@public/ts/composables/useUploads'
import { $t } from '@public/ts/shared/i18n'
import { store as uploadStore } from '@routes/public/uploads'
import { cn } from '@shared/lib/utils'
import { ImageUp, LoaderCircle, Trash2 } from 'lucide-vue-next'
import { computed, ref } from 'vue'

type Logo = { id: number; name: string; imageUrl: string; destroyUrl: string }

const props = defineProps<{
  logos: Logo[]
  storeUrl: string
}>()

/** Logos keep their transparency and sharp letters, so they are uploaded as they are. */
const uploads = useUploads({ url: uploadStore.url(), compress: false })
const saving = ref(false)
const removing = ref<number | null>(null)
const error = ref<string | null>(null)

const busy = computed(() => uploads.busy.value || saving.value)

const choose = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''

  if (files.length === 0) return

  error.value = null
  await uploads.add(files)

  const failed = uploads.pending.value.find((upload) => upload.status === 'failed')
  const ready = uploads.take()

  if (failed) error.value = failed.error

  if (ready.length === 0) return

  saving.value = true
  router.post(
    props.storeUrl,
    { logos: ready.map((upload) => upload.id) },
    {
      preserveScroll: true,
      only: ['branding'],
      onError: (errors) => (error.value = Object.values(errors)[0] ?? null),
      onFinish: () => (saving.value = false),
    },
  )
}

const remove = (logo: Logo) => {
  removing.value = logo.id
  router.delete(logo.destroyUrl, {
    preserveScroll: true,
    only: ['branding'],
    onFinish: () => (removing.value = null),
  })
}
</script>
