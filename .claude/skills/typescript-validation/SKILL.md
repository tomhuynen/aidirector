---
name: typescript-validation
description: >-
  Validates TypeScript after completing each feature. Runs type checking and fixes errors
  before moving to the next task. Ensures type safety across the DataTable implementation.
---

# TypeScript Validation After Each Feature

## Core Principle

**After completing each feature implementation, validate TypeScript and fix any errors before moving on.**

## Workflow

### After Each Feature Completion

1. **Run TypeScript validation**:
   ```bash
   npx vue-tsc -p tsconfig.json --noEmit
   ```

2. **If errors occur**, fix them immediately:
   - Type mismatches in props/emits
   - Missing type imports
   - Incorrect generic parameters
   - Union type narrowing issues

3. **Common fixes**:
   - Add missing properties to interfaces in `types.ts`
   - Import types from the correct modules
   - Use proper type guards for union types
   - Add `as const` for literal types

## When to Run

- After implementing a new component
- After modifying existing component props
- After updating `types.ts`
- After adding new composables
- Before marking a feature as complete

## Common TypeScript Issues in DataTable

### 1. Props Type Mismatch
```typescript
// Wrong - using wrong interface
defineProps<{ action: TableAction }>()

// Right - check what the component actually receives
defineProps<{ action: ResolvedAction & { _index: number } }>()
```

### 2. Missing Optional Chaining
```typescript
// Wrong - might be undefined
const url = itemAction.url

// Right - handle undefined
const url = typeof itemAction === 'string' ? itemAction : itemAction?.url
```

### 3. Component Import Types
```typescript
// For lucide-vue-next icons
import type { Component } from 'vue'
const getIconComponent = (name?: string): Component | null => { ... }
```

### 4. Event Emit Types
```typescript
// Define emit types explicitly
const emit = defineEmits<{
  'action-error': [error: unknown]
  'update:modelValue': [value: string]
}>()
```

## Checklist

Before moving to next feature:
- [ ] `npx vue-tsc -p tsconfig.json --noEmit` passes
- [ ] No TypeScript errors in modified files
- [ ] Types in `types.ts` match PHP API
- [ ] Imports are correct and minimal
