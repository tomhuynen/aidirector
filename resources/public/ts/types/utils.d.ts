// Re-export everything from the bridge (single source of truth)
export type { App, components, Inertia, operations, PageProps, paths } from './schema-bridge'

// Legacy Scramble-based type utilities (kept for HTTP client typing)
import type { paths } from './schema-bridge'

type MethodKeys = keyof paths[keyof paths]

export type Response<T extends keyof paths, M extends MethodKeys> = M extends keyof paths[T]
  ? paths[T][M]['responses'][200]['content']['application/json'] extends infer R
    ? R extends string
      ? never
      : R
    : never
  : never

export type GetResponse<T extends keyof paths> = Response<T, 'get'>
export type PostResponse<T extends keyof paths> = Response<T, 'post'>
export type PutResponse<T extends keyof paths> = Response<T, 'put'>
export type PatchResponse<T extends keyof paths> = Response<T, 'patch'>
export type DeleteResponse<T extends keyof paths> = Response<T, 'delete'>

export type Request<T extends keyof paths, M extends MethodKeys> = M extends keyof paths[T]
  ? paths[T][M] extends { requestBody?: { content: { 'application/json': infer R } } }
    ? R
    : never
  : never

export type GetRequest<T extends keyof paths> = Request<T, 'get'>
export type PostRequest<T extends keyof paths> = Request<T, 'post'>
export type PutRequest<T extends keyof paths> = Request<T, 'put'>
export type PatchRequest<T extends keyof paths> = Request<T, 'patch'>
export type DeleteRequest<T extends keyof paths> = Request<T, 'delete'>
