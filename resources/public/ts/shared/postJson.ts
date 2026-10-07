/** A request that came back with an error, with the first message the server gave. */
export class PostJsonError extends Error {
  constructor(
    public readonly status: number,
    message: string,
  ) {
    super(message)
  }
}

const xsrfToken = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '')

/**
 * Posts JSON to an endpoint of the app and returns its JSON answer, for
 * calls that change no page, like the plan chat or picking a person to move.
 */
export const postJson = async <T>(url: string, body: unknown): Promise<T> => {
  const response = await fetch(url, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': xsrfToken(),
    },
    body: JSON.stringify(body),
  })

  if (!response.ok) {
    let message = String(response.status)

    try {
      const answer = (await response.json()) as { message?: string; errors?: Record<string, string[]> }
      message = Object.values(answer.errors ?? {})[0]?.[0] ?? answer.message ?? message
    } catch {
      // Not JSON: keep the status.
    }

    throw new PostJsonError(response.status, message)
  }

  return (await response.json()) as T
}
