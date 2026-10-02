<template>
  <DropdownMenu>
    <DropdownMenuTrigger as-child>
      <button
        type="button"
        class="relative flex size-9 items-center justify-center rounded-full text-muted-foreground ring-offset-background transition-colors outline-none hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
        :aria-label="hasUnread ? $t(':count unread notifications', { count: String(unread) }) : $t('Notifications')"
      >
        <Bell class="size-5" />
        <span
          v-if="hasUnread"
          class="absolute top-1 right-1 flex min-w-4 items-center justify-center rounded-full bg-signal px-1 text-[10px] leading-4 font-semibold text-primary-foreground tabular-nums"
        >
          {{ unread > 9 ? '9+' : unread }}
        </span>
      </button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-80">
      <div class="flex items-center justify-between gap-2 px-2 py-1.5">
        <DropdownMenuLabel class="p-0">{{ $t('Notifications') }}</DropdownMenuLabel>
        <button
          v-if="hasUnread"
          type="button"
          class="text-xs text-muted-foreground transition-colors hover:text-foreground"
          @click.prevent="markAllRead"
        >
          {{ $t('Mark all as read') }}
        </button>
      </div>
      <DropdownMenuSeparator />

      <p v-if="items.length === 0" class="px-2 py-6 text-center text-sm text-muted-foreground">
        {{ $t('When something you asked for is ready, it shows up here.') }}
      </p>

      <div v-else class="max-h-96 overflow-y-auto">
        <DropdownMenuItem v-for="item in items" :key="item.id" class="flex items-start gap-3 py-2" @select="open(item)">
          <img
            v-if="item.imageUrl"
            :src="item.imageUrl"
            alt=""
            class="size-10 shrink-0 rounded-md border border-border bg-paper-deep object-cover"
            loading="lazy"
          />
          <span
            v-else
            :class="
              cn(
                'flex size-10 shrink-0 items-center justify-center rounded-md border border-border',
                item.failed ? 'text-destructive' : 'text-signal',
              )
            "
          >
            <CircleAlert v-if="item.failed" class="size-4" />
            <CircleCheck v-else class="size-4" />
          </span>
          <span class="min-w-0 flex-1 space-y-0.5">
            <span :class="cn('block text-sm leading-snug', !item.read && 'font-medium')">{{ item.title }}</span>
            <span class="block text-xs text-muted-foreground">{{ ago(item.createdAt) }}</span>
          </span>
          <span v-if="!item.read" class="mt-1.5 size-2 shrink-0 rounded-full bg-signal" :aria-label="$t('Unread')" />
        </DropdownMenuItem>
      </div>

      <template v-if="permission === 'default'">
        <DropdownMenuSeparator />
        <DropdownMenuItem class="text-sm" @select.prevent="askPermission">
          <BellRing class="size-4" />
          {{ $t('Also alert me when this tab is in the background') }}
        </DropdownMenuItem>
      </template>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
<script setup lang="ts">
import { useNotifications } from '@public/ts/composables/useNotifications'
import { $t } from '@public/ts/shared/i18n'
import type { Account } from '@public:types/shared'
import { cn } from '@shared/lib/utils'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@shared:ui/dropdown-menu'
import { Bell, BellRing, CircleAlert, CircleCheck } from 'lucide-vue-next'
import { onBeforeUnmount, onMounted } from 'vue'

const props = defineProps<{
  account: Account
}>()

const { items, unread, hasUnread, permission, start, stop, open, markAllRead, askPermission } = useNotifications({
  list: props.account.links.notifications,
  read: props.account.links.notificationsRead,
})

onMounted(start)
onBeforeUnmount(stop)

const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' })

/**
 * "2 minutes ago", "yesterday": how long ago a notification arrived.
 */
const ago = (iso: string) => {
  const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000)
  const steps: [Intl.RelativeTimeFormatUnit, number][] = [
    ['day', 86_400],
    ['hour', 3_600],
    ['minute', 60],
  ]

  for (const [unit, size] of steps) {
    if (Math.abs(seconds) >= size) return relative.format(Math.round(seconds / size), unit)
  }

  return $t('just now')
}
</script>
