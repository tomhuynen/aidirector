import type {
  BadgeData,
  ButtonVariant,
  CellSlotProps,
  FilterValue,
  HeaderSlotProps,
  ImageSlotProps,
  OptionItem,
  PaginationMeta,
  TableAction,
  TableCellImageData,
  TableColumn,
  TableExport,
  TableFilter,
  TableItem,
  TableResource,
  TableSlotProps,
  TableState,
  TableView,
  UseActionsReturn,
  UseTableReturn,
} from '@inertiaui/table-vue'

// Library types that match the PHP payload and can be used as-is.
export type {
  BadgeData,
  ButtonVariant,
  CellSlotProps,
  FilterValue,
  HeaderSlotProps,
  ImageSlotProps,
  OptionItem,
  PaginationMeta,
  TableAction,
  TableCellImageData,
  TableColumn,
  TableExport,
  TableFilter,
  TableItem,
  TableResource,
  TableSlotProps,
  TableState,
  TableView,
  UseActionsReturn,
  UseTableReturn,
}

// ── Local types ────────────────────────────────────────────────────────────
// Declared locally because the library either doesn't re-export them, or its
// internal type is incomplete/wrong vs the PHP payload.

/**
 * Shape serialized by `InertiaUI\Table\Url::toArray()`. Covers `_row_url`,
 * entries in `_column_urls`, and the URL portion of `_actions[index]`.
 * Library's internal `TableItemRowUrl` / `RowActionUrl` are subsets and are
 * not re-exported from the package.
 */
export interface TableItemUrl {
  url?: string
  preserveScroll?: boolean
  preserveState?: boolean
  openInNewTab?: boolean
  asDownload?: boolean | string
  disabled?: boolean
  hidden?: boolean
  modal?: boolean | Record<string, unknown>
  prefetch?: boolean | string
  cacheFor?: unknown
}

/**
 * Raw shape of `item._actions[index]` as built in `QueryBuilder::transformModel()`.
 * Either a plain URL string or an object with URL fields plus a dynamic `label`.
 */
export type RowActionItem = TableItemUrl & { label?: string }

/**
 * Mirrors the library's internal `ActionItem` (not re-exported). Produced by
 * `getActionForItem()` — a `TableAction` resolved against a row, carrying the
 * extra fields needed to render a button/link.
 */
export interface ActionItem extends TableAction {
  bindings: Record<string, unknown>
  isVisible: boolean
  componentType: string
  prefetch?: boolean | string
  cacheFor?: unknown
  modal?: boolean | Record<string, unknown>
}

/**
 * Shape serialized by `InertiaUI\Table\Image::toArray()`. Library's internal
 * `TableItemColumnImage` only exposes `{url, icon, position}` and is not
 * re-exported, so we declare the full payload here.
 */
export interface ColumnImage {
  url?: string | string[]
  icon?: string
  position?: 'start' | 'end'
  size?: 'small' | 'medium' | 'large' | 'extra-large' | 'custom'
  rounded?: boolean
  width?: number
  height?: number
  class?: string
  alt?: string
  title?: string
  remaining?: number
}

/** Saved-views payload. Library's `TableViewsConfig` is not re-exported. */
export interface TableViews {
  storeUrl: string
  query: Record<string, unknown>
  data?: TableView[]
}

/**
 * Empty-state payload. The library's `EmptyState` / `EmptyStateLink` types
 * describe a different shape (`links` with flat `{label, href, target, ...}`)
 * than what `InertiaUI\Table\EmptyState::toArray()` actually serializes
 * (`actions` with nested `url` objects).
 */
export interface EmptyStateConfig {
  title?: string
  message?: string
  icon?: boolean | string
  actions?: EmptyStateAction[]
  dataAttributes?: Record<string, string>
  meta?: Record<string, unknown>
}

export interface EmptyStateAction {
  label: string
  url: EmptyStateActionUrl
  variant?: ButtonVariant
  buttonClass?: string
  icon?: string
  dataAttributes?: Record<string, string>
  meta?: Record<string, unknown>
}

export type EmptyStateActionUrl = TableItemUrl

/**
 * Strongly-typed value carried inside `FilterValue.value`. The library types
 * the field as `unknown`; our filter UI operates on concrete shapes.
 */
export type FilterValueScalar = string | number | [number, number] | string[] | number[] | null

// ── Component contracts ───────────────────────────────────────────────────

export interface DataTableProps {
  resource: TableResource
  onRowClick?: (item: TableItem, column: TableColumn) => void
}

export interface ActionResult {
  keys: (string | number)[]
  response?: unknown
  onFinish?: () => void
}

export interface ExportResult {
  keys: (string | number)[]
  response?: unknown
  error?: unknown
}
