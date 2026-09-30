export type CompressImageOptions = {
  /** Longest edge after downscaling, in pixels. */
  maxEdge?: number
  /** JPEG or WebP quality between 0 and 1. */
  quality?: number
  type?: 'image/jpeg' | 'image/webp'
  /** Files under this size that need no downscaling are returned untouched. */
  keepUnder?: number
}

const decodable = (file: File) =>
  file.type.startsWith('image/') && file.type !== 'image/gif' && file.type !== 'image/svg+xml'

/**
 * Downscales an image in the browser before it is uploaded. A phone photo
 * is 10 MB for pixels nobody needs; the server keeps its own conversion as
 * the canonical size, this only makes the transfer cheap. Anything the
 * browser cannot decode (HEIC outside Safari) is returned as it was.
 */
export async function compressImage(file: File, options: CompressImageOptions = {}): Promise<File> {
  const { maxEdge = 2048, quality = 0.85, type = 'image/jpeg', keepUnder = 1_500_000 } = options

  if (!decodable(file)) {
    return file
  }

  let bitmap: ImageBitmap

  try {
    bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
  } catch {
    return file
  }

  const scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height))

  if (scale === 1 && file.size <= keepUnder) {
    bitmap.close()

    return file
  }

  const canvas = document.createElement('canvas')
  canvas.width = Math.max(1, Math.round(bitmap.width * scale))
  canvas.height = Math.max(1, Math.round(bitmap.height * scale))

  const context = canvas.getContext('2d')

  if (!context) {
    bitmap.close()

    return file
  }

  context.drawImage(bitmap, 0, 0, canvas.width, canvas.height)
  bitmap.close()

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, type, quality))

  if (!blob || blob.size >= file.size) {
    return file
  }

  const extension = type === 'image/webp' ? '.webp' : '.jpg'
  const name = file.name.replace(/\.[^.]+$/, '') + extension

  return new File([blob], name, { type, lastModified: file.lastModified })
}
