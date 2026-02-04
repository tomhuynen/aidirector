const now = new Date()

export function isPast(value: string) {
  const date = new Date(value)

  return date.getTime() < now.getTime()
}

export function isDate(value: unknown): boolean {
  if (typeof value !== 'string') {
    return false
  }

  return !isNaN(new Date(value).getTime())
}
