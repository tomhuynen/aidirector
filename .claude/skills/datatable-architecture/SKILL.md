---
name: datatable-architecture
description: >-
  Monitors DataTable architecture for code duplication. Tracks shared utilities and composables.
  Activates when implementing features across multiple DataTable components. Ensures DRY principles
  by extracting repeated logic to shared modules.
---

# DataTable Architecture Monitor

## Core Principle

**Avoid code duplication.** When implementing a pattern in one component, check if it exists elsewhere and extract to shared utilities.

## Shared Modules

### Location
```
resources/shared/components/ui/data-table/
├── composables/           # Shared composables (useX functions)
│   └── useIcons.ts        # Icon resolution utilities
├── utils/                 # Pure utility functions
│   └── icons.ts           # Icon name conversion
└── types.ts               # Shared TypeScript types
```

## Current Shared Utilities

### Icon Resolution (`composables/useIcons.ts`)
Converts icon names (kebab-case or PascalCase) to lucide-vue-next components.

```typescript
// Usage in components:
import { useIcons } from './composables/useIcons'
const { getIconComponent } = useIcons()

// In template:
<component :is="getIconComponent('pencil-line')" />
```

**Used by:**
- DataTableRowActions.vue
- DataTableCellBoolean.vue
- DataTableEmpty.vue

### Boolean Value Conversion (`DataTableBody.vue`)
Converts various falsy values to boolean (`toBooleanValue`).
- Handles: false, 0, '0', 'false', 'No', 'no', null, undefined
- Consider extracting if used elsewhere

### Button Variant Mapping (`DataTableEmpty.vue`)
Maps API variants to shadcn Button variants (`getButtonVariant`).
- Maps 'danger' → 'destructive', others → 'default'
- Consider extracting if used elsewhere

## Duplication Checklist

Before implementing any helper function, check:

1. **Icon resolution** → Use `useIcons` composable
2. **URL navigation** → Use `visitUrl` from `@inertiaui/table-vue`
3. **Action handling** → Use `useActions` from `@inertiaui/table-vue`
4. **Table state** → Use `useTable` from `@inertiaui/table-vue`
5. **Clause symbols/labels** → Consider extracting if used in multiple components

## When to Extract

Extract to shared module when:
- Same logic appears in 2+ components
- Function is pure (no component-specific dependencies)
- Logic is complex enough that bugs could diverge

## Anti-Patterns

- Copy-pasting helper functions between components
- Inline complex logic that could be reused
- Component-specific utils that are actually generic
