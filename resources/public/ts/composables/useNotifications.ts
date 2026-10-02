import { router } from '@inertiajs/vue3'
import { $t } from '@public/ts/shared/i18n'
import { computed, ref } from 'vue'
import { toast } from 'vue-sonner'

import type { GetResponse } from '../types/utils'

export type NotificationList = GetResponse<'/notifications'>
export type AppNotification = NotificationList['notifications'][number]

/** How often the app asks for new notifications while it is open. */
const POLL_MS = 10_000

const xsrfToken = () => decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] ?? '')

/*
 * One shared state for the whole app: both layouts mount the bell, but only
 * one poller runs, and a notification is announced only once.
 */
const items = ref<AppNotification[]>([])
const unread = ref(0)
const permission = ref<NotificationPermission | 'unsupported'>(
  typeof window !== 'undefined' && 'Notification' in window ? Notification.permission : 'unsupported',
)
const announced = new Set<string>()
let links: { list: string; read: string } | null = null
let timer: ReturnType<typeof setInterval> | null = null
let subscribers = 0
let since: string | null = null

const request = async <T>(url: string, init?: RequestInit): Promise<T | null> => {
  try {
    const response = await fetch(url, {
      credentials: 'same-origin',
      ...init,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(init?.body ? { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() } : {}),
      },
    })

    return response.ok ? ((await response.json()) as T) : null
  } catch {
    return null
  }
}

const isCurrentPage = (url: string) => {
  try {
    return new URL(url, window.location.href).pathname === window.location.pathname
  } catch {
    return false
  }
}

const markRead = async (ids?: string[]) => {
  if (!links) return

  items.value = items.value.map((item) => (!ids || ids.includes(item.id) ? { ...item, read: true } : item))

  const result = await request<{ unread: number }>(links.read, {
    method: 'POST',
    body: JSON.stringify(ids ? { ids } : {}),
  })

  if (result) unread.value = result.unread
}

const open = (item: AppNotification) => {
  void markRead([item.id])
  router.visit(item.url)
}

/**
 * Tells the director about a result that just came in: a toast in the app,
 * and a desktop notification as well when the tab is in the background.
 */
const announce = (item: AppNotification) => {
  if (announced.has(item.id)) return
  announced.add(item.id)

  // The page that shows the result is already open; it updates by itself.
  const here = isCurrentPage(item.url)
  const options = {
    duration: 10_000,
    action: here ? undefined : { label: $t('Open'), onClick: () => open(item) },
  }

  if (item.failed) {
    toast.error(item.title, options)
  } else {
    toast.success(item.title, options)
  }

  if (here) void markRead([item.id])

  if (document.hidden && permission.value === 'granted') {
    const desktop = new Notification(item.title, { icon: item.imageUrl ?? undefined, tag: item.id })

    desktop.onclick = () => {
      window.focus()
      open(item)
      desktop.close()
    }
  }
}

const load = async () => {
  if (!links) return

  const url = since ? `${links.list}?after=${encodeURIComponent(since)}` : links.list
  const result = await request<NotificationList>(url)

  if (!result) return

  unread.value = result.unread

  const fresh = result.notifications.filter((item) => !items.value.some((known) => known.id === item.id))

  // The first load fills the bell; only what arrives after that is announced.
  if (since !== null) [...fresh].reverse().forEach(announce)
  else fresh.forEach((item) => announced.add(item.id))

  items.value = [...fresh, ...items.value].slice(0, 20)
  since = result.now
}

/**
 * The director's notifications, kept up to date while the app is open.
 * Every component that calls it shares the same list and poller.
 */
export function useNotifications(urls: { list: string; read: string }) {
  links = urls

  const start = () => {
    subscribers += 1

    if (timer === null) {
      void load()
      timer = setInterval(() => void load(), POLL_MS)
    }
  }

  const stop = () => {
    subscribers = Math.max(0, subscribers - 1)

    if (subscribers === 0 && timer !== null) {
      clearInterval(timer)
      timer = null
    }
  }

  const askPermission = async () => {
    if (permission.value === 'unsupported') return

    permission.value = await Notification.requestPermission()
  }

  return {
    items,
    unread,
    hasUnread: computed(() => unread.value > 0),
    permission,
    start,
    stop,
    open,
    markAllRead: () => markRead(),
    askPermission,
  }
}
