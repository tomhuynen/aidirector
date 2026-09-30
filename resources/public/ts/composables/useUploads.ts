import { compressImage } from '@shared/lib/compressImage'
import { computed, type MaybeRefOrGetter, ref, toValue } from 'vue'

import type { PostResponse } from '../types/utils'

export type UploadResult = PostResponse<'/uploads'>

export type PendingUpload = {
  key: string
  name: string
  /** Local object URL for a thumbnail, null for files that are not images. */
  previewUrl: string | null
  status: 'uploading' | 'ready' | 'failed'
  /** The staging upload's id once the server has it. */
  id: string | null
  error: string | null
}

type UseUploadsOptions = {
  url: MaybeRefOrGetter<string>
  /** Downscale images in the browser before sending them. On by default. */
  compress?: boolean
}

let nextKey = 0

const xsrfToken = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '')

const errorFrom = async (response: Response): Promise<string> => {
  try {
    const data = (await response.json()) as { message?: string; errors?: Record<string, string[]> }

    return data.errors?.file?.[0] ?? data.message ?? response.statusText
  } catch {
    return response.statusText
  }
}

/**
 * Stages files on the server ahead of the request that will claim them
 * (a chat turn, a form). Keeps a preview per file while it is in flight.
 */
export function useUploads(options: UseUploadsOptions) {
  const pending = ref<PendingUpload[]>([])
  const busy = computed(() => pending.value.some((upload) => upload.status === 'uploading'))
  const ready = computed(() => pending.value.filter((upload) => upload.status === 'ready'))

  const upload = async (entry: PendingUpload, file: File) => {
    const body = new FormData()
    body.append('file', options.compress === false ? file : await compressImage(file))

    try {
      const response = await fetch(toValue(options.url), {
        method: 'POST',
        body,
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-XSRF-TOKEN': xsrfToken(),
        },
      })

      if (!response.ok) {
        throw new Error(await errorFrom(response))
      }

      const result = (await response.json()) as UploadResult

      entry.id = result.id
      entry.status = 'ready'
    } catch (caught) {
      entry.status = 'failed'
      entry.error = caught instanceof Error ? caught.message : String(caught)
    }
  }

  const add = async (files: Iterable<File>) => {
    const entries = Array.from(files).map((file) => {
      const entry: PendingUpload = {
        key: `upload-${++nextKey}`,
        name: file.name,
        previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null,
        status: 'uploading',
        id: null,
        error: null,
      }

      pending.value.push(entry)

      return [pending.value[pending.value.length - 1], file] as const
    })

    await Promise.all(entries.map(([entry, file]) => upload(entry, file)))
  }

  const remove = (key: string) => {
    const index = pending.value.findIndex((upload) => upload.key === key)

    if (index !== -1) {
      pending.value.splice(index, 1)
    }
  }

  /** Hands over the uploads that made it and empties the tray. */
  const take = () => {
    const taken = ready.value
    pending.value = []

    return taken
  }

  return { pending, busy, ready, add, remove, take }
}
