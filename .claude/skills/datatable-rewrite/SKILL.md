---
name: datatable-rewrite
description: >-
  Rewrites @inertiaui/table-vue components using shadcn-vue. Activates when working on DataTable
  components, table features, row actions, bulk actions, filters, pagination, or any data-table
  related Vue components. Always checks original implementation before making changes.
---

# DataTable Rewrite Specialist

## When to Apply

Activate this skill when:
- Working on any DataTable component
- Implementing table features (sorting, filtering, pagination, actions)
- Adding new column types or cell renderers
- Fixing bugs in data-table components
- Adding features from the original @inertiaui/table-vue

## Core Principle

**ALWAYS check the original implementation before making changes.** The original code is the source of truth for:
- Property names and types
- API contracts
- Feature behavior
- Edge cases

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
├── types.ts                   # TypeScript definitions
└── index.ts                   # Barrel exports
```

### Original Implementation (ALWAYS CHECK THESE)
```
vendor/inertiaui/table/vue/src/
├── Table.vue                  # Main component (622 lines)
├── table.js                   # useTable composable
├── actions.js                 # useActions composable
├── urlHelpers.js              # URL navigation helpers
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
  // Boolean column specific
  trueIcon?: string
  falseIcon?: string
  // Action column specific
  asDropdown?: boolean
  // Sticky
  stickable?: boolean
}
```

### BooleanColumn Behavior
When no icons are configured, BooleanColumn returns the **label** ("Yes"/"No"), not the boolean value.
```php
// From BooleanColumn.php mapForTable()
if ($bool && $this->getTrueIcon()) return $bool;
if (!$bool && $this->getFalseIcon()) return $bool;
return parent::mapForTable($value, ...); // Returns label string
```

Handle in frontend with:
```typescript
const toBooleanValue = (value: unknown): boolean => {
  if (value === false || value === 0 || value === '0' ||
      value === 'false' || value === 'No' || value === 'no' ||
      value === null || value === undefined) {
    return false
  }
  return Boolean(value)
}
```

### TableRow (from Table.php)
```typescript
interface TableRow {
  _primary_key: string | number
  _is_selectable?: boolean
  _actions?: Record<number, RowActionItem | string>  // Indexed by action position
  _data_attributes?: Record<string, string>
  _column_images?: Record<string, ColumnImage>
  _column_urls?: Record<string, string | ColumnUrl>
  _row_url?: string | ColumnUrl
  [key: string]: unknown  // Column values
}
```

### Filter Types
```typescript
interface TableFilterDefinition {
  attribute: string
  label: string
  type: 'text' | 'numeric' | 'date' | 'set' | 'boolean'
  clauses: string[]
  options?: FilterOption[]  // For set filters
  multiple?: boolean        // Multi-select for set
  hasDefaultValue?: boolean
}

interface FilterState {
  enabled: boolean
  clause: string
  value: FilterValue
  new?: boolean  // Just added, auto-open popover
}

type FilterValue = string | number | [number, number] | string[] | number[] | null
```

## shadcn-vue Components to Use

- **Dialog** - Confirmation dialogs, modals
- **Sheet** - Slideovers
- **DropdownMenu** - Action menus, column toggle
- **Button** - Action buttons
- **Checkbox** - Row selection (use `model-value`, NOT `checked`)
- **Popover** - Filter badges
- **Calendar** - Date filters
- **NativeSelect** - Set filters (NOT shadcn Select - has binding issues)
- **Input** - Text/numeric filters
- **Badge** - Badge columns, filter pills

## Common Pitfalls

1. **Wrong property names** - Always check PHP toArray() methods
2. **Nested vs flat properties** - `confirmationTitle` NOT `confirm.title`
3. **Boolean coercion** - `Boolean("No")` is `true`, handle label strings
4. **Checkbox binding** - Use `:model-value`, not `:checked` (reka-ui)
5. **Select binding** - Use NativeSelect for set filters, not shadcn Select
6. **Icon resolution** - Convert kebab-case to PascalCase for lucide-vue-next

## Workflow

1. **Before implementing any feature:**
   - Read the original Vue component
   - Read the PHP class (for toArray() output)
   - Check helpers.js for utility functions

2. **During implementation:**
   - Match property names exactly
   - Use shadcn-vue components
   - Follow existing patterns in our components

3. **After implementation:**
   - Run `vendor/bin/pint --dirty`
   - Build and test the feature
   - Verify edge cases match original behavior

## Feature Parity Document

See `docs/data-table-feature-analysis.md` for:
- Full feature comparison (97 features)
- Current coverage (~68%)
- Priority improvements list
- Quick wins identified
