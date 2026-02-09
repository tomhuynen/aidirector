declare module '@inertiaui/modal-vue'

declare module '@inertiaui/table-vue' {
  // inertiaui-table.d.ts

  import type { Component, ComputedRef, Ref } from 'vue'

  // Type definitions for action items
  interface ActionItem {
    type?: string
    variant?: string
    buttonClass?: string
    asDownload?: boolean | string
    disabled?: boolean
    dataAttributes?: Record<string, any>
    [key: string]: any
  }

  // Type definitions for clickable column options
  interface ClickableColumnOptions {
    url?: string
    disabled?: boolean
    openInNewTab?: boolean
    preserveScroll?: boolean
    preserveState?: boolean
    modal?: boolean | Record<string, any>
    [key: string]: any
  }

  // Type definitions for table column
  interface TableColumn {
    attribute: string
    label?: string
    sortable?: boolean
    visibleByDefault?: boolean
    type?: string
    multiple?: boolean
  }

  // Type definitions for table filter (used in state.filters)
  interface TableFilter {
    attribute: string
    type: string
    enabled?: boolean
    clause?: string
    value?: any
    new?: boolean
    hasDefaultValue?: boolean
    label?: string
    clauses?: string[]
    options?: Array<{ value: string | number; label: string }>
    multiple?: boolean
  }

  // Type definitions for table state
  interface TableState {
    columns: Record<string, boolean>
    filters: Record<string, TableFilter>
    perPage: number
    search: string | null
    sort: string | null
    sticky: string[]
  }

  // Type definitions for table options (accepts full resource from server)
  interface TableOptions {
    name: string
    columns: TableColumn[]
    filters: TableFilter[]
    defaultSort?: string | null
    defaultPerPage?: number
    paginationType?: 'full' | 'simple' | 'cursor'
    debounceTime?: number
    scrollPositionAfterPageChange?: 'preserve' | 'topOfTable' | 'none' | 'top'
    reloadProps?: string[]
    hasBulkActions?: boolean
    hasExports?: boolean
    hasExportsThatLimitsToSelectedRows?: boolean
    // Additional fields from full TableResource
    actions?: any[]
    exports?: any[]
    results?: any
    state?: any
    pagination?: boolean
    perPageOptions?: number[]
    hasFilters?: boolean
    hasSearch?: boolean
    hasToggleableColumns?: boolean
    autofocus?: 'search' | null
    emptyState?: boolean | Record<string, any>
    stickyHeader?: boolean
    views?: any
  }

  // Type definitions for table hook return
  interface TableHook {
    addFilter: (filter: TableFilter) => void
    hasBulkActions: ComputedRef<boolean>
    hasExports: ComputedRef<boolean>
    hasFilters: ComputedRef<boolean>
    hasSelectableRows: ComputedRef<boolean>
    hasStickyColumns: ComputedRef<boolean>
    isNavigating: Ref<boolean>
    isSortedByColumn: (column: TableColumn) => false | 'asc' | 'desc'
    makeSticky: (column: TableColumn) => void
    putState: (state: Record<string, any>) => void
    removeFilter: (filter: TableFilter) => void
    setPerPage: (perPage: number) => void
    setSort: (sort: string) => void
    sortByColumn: (column: TableColumn) => void
    state: Ref<TableState>
    toggleColumn: (column: TableColumn) => void
    undoSticky: (column: TableColumn) => void
    visitPaginationUrl: (url: string, callback?: () => void) => void
    visitTableUrl: (url: string, options?: boolean | Record<string, any>) => void
  }

  // Type definitions for translations
  interface Translations {
    actions_button?: string
    exports_header?: string
    bulk_actions_header?: string
    filters_button?: string
    add_filter_header?: string
    between_values_and?: string
    loading_placeholder?: string
    rows_per_page?: string
    current_page?: string
    current_page_of_last?: string
    columns_button?: string
    toggle_columns_header?: string
    sort_asc?: string
    sort_desc?: string
    hide_column?: string
    search_placeholder?: string
    [key: string]: string | undefined
  }

  // Export declarations
  export const Table: Component
  export function getActionForItem(action: ActionItem, item: any): ActionItem
  export function getClickableColumn(
    column: TableColumn,
    row: any,
    event?: MouseEvent | null,
  ): ClickableColumnOptions | undefined
  export function setClauseSymbols(symbols: Record<string, string>): void
  export function setDarkModeStrategy(strategy: 'auto' | 'class' | 'selector' | 'media' | (() => boolean)): void
  export function setIconResolver(resolver: (...args: any[]) => any): void
  export function setTranslations(translations: Translations): void
  export function useActions(): any // You might want to define a more specific return type
  export function useTable(options: TableOptions): TableHook
  export function visitUrl(options: ClickableColumnOptions): void
}
