import type { TableHook } from '@inertiaui/table-vue'
import type { ComputedRef, Ref } from 'vue'

// Re-export the library's TableHook for convenience
export type UseTableReturn = TableHook

// Resource type from Inertia Table (what comes from the server)
export interface TableResource {
  name: string
  columns: TableColumn[]
  filters: TableFilterDefinition[]
  actions: TableAction[]
  exports?: TableExport[]
  results: TableResults
  state: TableState
  pagination?: boolean
  paginationType?: 'full' | 'simple' | 'cursor'
  perPageOptions?: number[]
  defaultPerPage?: number
  defaultSort?: string | null
  debounceTime?: number
  hasBulkActions?: boolean
  hasExports?: boolean
  hasExportsThatLimitsToSelectedRows?: boolean
  hasFilters?: boolean
  hasSearch?: boolean
  hasToggleableColumns?: boolean
  autofocus?: 'search' | null
  emptyState?: boolean | EmptyStateConfig
  stickyHeader?: boolean
  scrollPositionAfterPageChange?: 'preserve' | 'topOfTable' | 'top'
  reloadProps?: string[]
  views?: TableViews
}

export interface TableColumn {
  attribute: string
  header: string
  sortable: boolean
  toggleable: boolean
  visibleByDefault: boolean
  type: 'text' | 'badge' | 'boolean' | 'image'
  alignment: 'left' | 'center' | 'right'
  wrap?: boolean
  truncate?: number
  cellClass?: string
  headerClass?: string
  trueIcon?: string
  falseIcon?: string
  asDropdown?: boolean
}

// Filter definition (what comes from the server in resource.filters)
export interface TableFilterDefinition {
  attribute: string
  label: string
  type: 'text' | 'numeric' | 'date' | 'set' | 'boolean'
  clauses: string[]
  options?: FilterOption[]
  multiple?: boolean
  hasDefaultValue?: boolean
}

export interface FilterOption {
  value: string | number
  label: string
}

export interface TableAction {
  key: string
  label: string
  icon?: string
  url: string
  method?: 'get' | 'post' | 'put' | 'patch' | 'delete'
  authorized: boolean
  asRowAction?: boolean
  asBulkAction?: boolean
  isBulk?: boolean
  isCustom?: boolean
  isLink?: boolean
  confirmationRequired?: boolean
  confirmationTitle?: string
  confirmationMessage?: string
  confirmationConfirmButton?: string
  confirmationCancelButton?: string
  showLabel?: boolean
  variant?: 'default' | 'danger'
  asDownload?: boolean | string
  disabled?: boolean
}

export interface TableExport {
  key: string
  label: string
  url: string
  limitToSelectedRows?: boolean
}

export interface TableResults {
  data: TableRow[]
  total?: number
  per_page?: number
  current_page?: number
  last_page?: number
  first_page_url?: string
  last_page_url?: string
  next_page_url?: string | null
  prev_page_url?: string | null
  from?: number
  to?: number
}

// Row action item (from _actions on each row - indexed by action position)
export interface RowActionItem {
  url?: string
  isVisible?: boolean
  authorized?: boolean
  hidden?: boolean
  disabled?: boolean
  modal?: boolean | Record<string, unknown>
  asDownload?: boolean | string
  [key: string]: unknown
}

// Column URL configuration (for clickable columns)
export interface ColumnUrl {
  url: string
  disabled?: boolean
  openInNewTab?: boolean
  preserveScroll?: boolean
  preserveState?: boolean
  modal?: boolean | Record<string, unknown>
}

export interface TableRow {
  _primary_key: string | number
  _is_selectable?: boolean
  _actions?: Record<number, RowActionItem | string>
  _data_attributes?: Record<string, string>
  _column_images?: Record<string, ColumnImage>
  _column_urls?: Record<string, string | ColumnUrl>
  _row_url?: string | ColumnUrl
  [key: string]: unknown
}

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

export interface BadgeValue {
  value: string
  variant?: string
}

export interface TableState {
  search: string
  sort: string | null
  perPage: number
  columns: Record<string, boolean>
  filters: Record<string, FilterState>
  sticky: string[]
}

export interface FilterState {
  enabled: boolean
  clause: string
  value: FilterValue
  new?: boolean
}

// Filter value can be string, number, array (for between/in clauses), or null
export type FilterValue = string | number | [number, number] | string[] | number[] | null

export interface EmptyStateConfig {
  title?: string
  message?: string
  icon?: boolean | string // true = default icon, false = no icon, string = custom icon name
  actions?: EmptyStateAction[]
  dataAttributes?: Record<string, string>
  meta?: Record<string, unknown>
}

export interface EmptyStateAction {
  label: string
  url: EmptyStateActionUrl
  variant?: 'info' | 'danger' | 'success' | 'warning' | 'default'
  buttonClass?: string
  icon?: string
  dataAttributes?: Record<string, string>
  meta?: Record<string, unknown>
}

export interface EmptyStateActionUrl {
  url: string
  disabled?: boolean
  hidden?: boolean
  modal?: boolean | Record<string, unknown>
  asDownload?: boolean | string
  preserveScroll?: boolean
  preserveState?: boolean
}

export interface TableViews {
  storeUrl: string
  query: Record<string, unknown>
  data: TableView[]
}

export interface TableView {
  id: string | number
  title: string
  state: TableState
  deleteUrl: string
}

// Actions composable return type
export interface UseActionsReturn {
  allItemsAreSelected: ComputedRef<boolean>
  isPerformingAction: Ref<boolean>
  performAction: (action: TableAction, keys?: (string | number)[]) => Promise<ActionResult>
  performAsyncExport: (tableExport: TableExport) => Promise<ExportResult>
  selectedItems: Ref<(string | number)[]>
  toggleItem: (id: string | number | '*') => void
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

// Component prop types
export interface DataTableProps {
  resource: TableResource
  onRowClick?: (item: TableRow, column: TableColumn) => void
}

export interface CellSlotProps {
  item: TableRow
  column: TableColumn
  value: unknown
  table: UseTableReturn
  actions: UseActionsReturn
}

export interface HeaderSlotProps {
  column: TableColumn
  table: UseTableReturn
  actions: UseActionsReturn
}
