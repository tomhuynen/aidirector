---
name: datatable-rewrite
description: >-
  Rewrites @inertiaui/table-vue components using shadcn-vue. Activates when working on DataTable
  components, table features, row actions, bulk actions, filters, pagination, column types,
  extracting composables from @inertiaui/table-vue, or any data-table related Vue components.
  Also activates when working with useTable, useActions, visitUrl, getActionForItem, or
  getClickableColumn.
---

# DataTable Rewrite Specialist

## Core Principles

1. **ALWAYS read the original implementation before making changes.** The original code is the source of truth.
2. **No code duplication.** Check shared composables/utils before implementing helpers. Extract to shared modules when logic appears in 2+ components.
3. **Use shadcn-vue components.** Never write custom UI when shadcn-vue has it.

## File References

### Our Implementation
```
resources/shared/components/ui/data-table/
├── DataTable.vue              # Main orchestrator
├── DataTableToolbar.vue       # Search, filters, bulk actions
├── DataTableHeader.vue        # Column headers
├── DataTableBody.vue          # Row rendering
├── DataTablePagination.vue    # Pagination controls
├── DataTableFilters.vue       # Active filters container
├── DataTableFilterBadge.vue   # Individual filter UI
├── DataTableAddFilter.vue     # Add filter dropdown
├── DataTableRowActions.vue    # Row action dropdown/buttons
├── DataTableBulkActions.vue   # Bulk actions dropdown
├── DataTableColumnToggle.vue  # Column visibility
├── DataTableEmpty.vue         # Empty state
├── DataTableCellBadge.vue     # Badge cell renderer
├── DataTableCellBoolean.vue   # Boolean cell renderer
├── DataTableCellImage.vue     # Image cell renderer
├── composables/               # Shared composables (useIcons.ts, etc.)
├── utils/                     # Pure utility functions
├── types.ts                   # TypeScript definitions
└── index.ts                   # Barrel exports
```

### Original Implementation (ALWAYS CHECK THESE)
```
vendor/inertiaui/table/vue/src/
├── Table.vue                  # Main component
├── table.js                   # useTable composable
├── actions.js                 # useActions composable
├── urlHelpers.js              # URL navigation helpers
├── agnosticUrlHelpers.js      # Core logic behind urlHelpers
├── useStickyTable.js          # Sticky column/header logic
├── clauses.js                 # Filter clause definitions
├── translations.js            # i18n system
├── helpers.js                 # Utility functions (blank, etc.)
└── [sub-components]           # Various UI components
```

### PHP Backend (for API reference)
```
vendor/inertiaui/table/src/
├── Action.php                 # Action definitions
├── Columns/                   # Column types (TextColumn, BooleanColumn, etc.)
├── Filters/                   # Filter types
└── Table.php                  # Main table class
```

## API Types (Source of Truth)

### TableAction (from Action.php toArray())
```typescript
interface TableAction {
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
  // Confirmation properties (flat, NOT nested)
  confirmationRequired?: boolean
  confirmationTitle?: string
  confirmationMessage?: string
  confirmationConfirmButton?: string
  confirmationCancelButton?: string
  // Display options
  showLabel?: boolean
  variant?: 'default' | 'danger'
  type?: string
  buttonClass?: string
  linkClass?: string
  dataAttributes?: Record<string, unknown>
  meta?: Record<string, unknown>
  asDownload?: boolean
  disabled?: boolean
}
```

### TableColumn (from Column.php toArray())
```typescript
interface TableColumn {
  attribute: string
  header: string
  sortable: boolean
  toggleable: boolean
  visibleByDefault: boolean
  type: 'text' | 'badge' | 'boolean' | 'image' | 'date'
  alignment: 'left' | 'center' | 'right'
  wrap?: boolean
  truncate?: number
  cellClass?: string
  headerClass?: string
  trueIcon?: string
  falseIcon?: string
  asDropdown?: boolean
  stickable?: boolean
}
```

### BooleanColumn Behavior
When no icons are configured, BooleanColumn returns the **label** ("Yes"/"No"), not the boolean value. Handle with `toBooleanValue()` helper.

### TableRow (from Table.php)
```typescript
interface TableRow {
  _primary_key: string | number
  _is_selectable?: boolean
  _actions?: Record<number, RowActionItem | string>
  _data_attributes?: Record<string, string>
  _column_images?: Record<string, ColumnImage>
  _column_urls?: Record<string, string | ColumnUrl>
  _row_url?: string | ColumnUrl
  [key: string]: unknown
}
```

### Filter Types
```typescript
interface TableFilterDefinition {
  attribute: string
  label: string
  type: 'text' | 'numeric' | 'date' | 'set' | 'boolean'
  clauses: string[]
  options?: FilterOption[]
  multiple?: boolean
  hasDefaultValue?: boolean
}

interface FilterState {
  enabled: boolean
  clause: string
  value: FilterValue
  new?: boolean
}

type FilterValue = string | number | [number, number] | string[] | number[] | null
```

## shadcn-vue Components to Use

| Need | Component | Notes |
|------|-----------|-------|
| Confirmation | Dialog | |
| Slideover | Sheet | |
| Action menus | DropdownMenu | |
| Row selection | Checkbox | Use `model-value`, NOT `checked` (reka-ui) |
| Filter badges | Popover | |
| Date filters | Calendar | |
| Set filters | NativeSelect | NOT shadcn Select (binding issues) |
| Text/numeric | Input | |
| Badge columns | Badge | |

## Extraction Reference (@inertiaui/table-vue → local)

### Import Map
| File | From `@inertiaui/table-vue` | Replacement Target |
|------|----------------------------|-------------------|
| `DataTable.vue` | `useTable`, `useActions` | `./composables/useTable`, `./composables/useActions` |
| `DataTableRowActions.vue` | `getActionForItem` | `./utils/urlHelpers` |
| `DataTableBody.vue` | `getClickableColumn`, `visitUrl` | `./utils/urlHelpers` |
| `DataTableEmpty.vue` | `visitUrl` | `./utils/urlHelpers` |
| `types.ts` | `TableHook` (type) | Define locally |

### Key Behavior of Original Functions

**visitUrl** — Normalizes string → object URL, opens new tab if `openInNewTab: true`, otherwise `router.visit()` with `preserveScroll` and `preserveState`.

**getActionForItem** — Transforms action with per-row overrides. Determines component type: `'a'` (download), `'button-component'`, `'button'`. Copies `dataAttributes` to bindings. Handles `asDownload`, `disabled`, `isVisible`, `variant`.

**getClickableColumn** — Skips action columns (`_actions`). Gets URL from `item._column_urls[column.attribute]` or `item._row_url`. Detects ctrl/cmd/middle-click → `openInNewTab: true`.

**useActions** — `selectedItems` ref (array of IDs, `['*']` = select all). `performAction` checks `authorized`, handles `isCustom`, POSTs with `{ keys, json: true }`. Clears selection after action.

**useTable** — State: `{ columns, filters, perPage, search, sort, sticky }`. Deep watches → debounced `router.visit()`. Builds query via `qs.stringify()`. Manages cancel tokens, scroll positioning, partial reloads.

### Keep Separate: @inertiaui/modal-vue
`visitModal` comes from `@inertiaui/modal-vue` (separate package). Do NOT try to extract it.

### Extraction Workflow
1. Read the original file in full (not just the function)
2. Write replacement in TypeScript, match behavior exactly
3. Update imports in consuming files
4. Run `npx vue-tsc -p tsconfig.json --noEmit`

## Common Pitfalls

1. **Wrong property names** — Always check PHP toArray() methods
2. **Nested vs flat properties** — `confirmationTitle` NOT `confirm.title`
3. **Boolean coercion** — `Boolean("No")` is `true`, handle label strings
4. **Checkbox binding** — Use `:model-value`, not `:checked` (reka-ui)
5. **Select binding** — Use NativeSelect for set filters, not shadcn Select
6. **Icon resolution** — Use `composables/useIcons.ts`, don't duplicate
7. **Cancel tokens** — Don't forget when extracting useTable
8. **qs format** — Backend expects specific query string format, don't break it

## Workflow

1. **Before implementing:** Read original Vue + PHP source, check existing shared utils
2. **During:** Match property names exactly, use shadcn-vue, follow existing patterns
3. **After:** Run `vendor/bin/pint --dirty`, run `npx vue-tsc`, test the feature
