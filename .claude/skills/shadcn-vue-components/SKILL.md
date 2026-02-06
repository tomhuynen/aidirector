---
name: shadcn-vue-components
description: >-
  Always checks shadcn-vue for appropriate components before writing custom implementations.
  Activates when building UI components, forms, dialogs, dropdowns, or any interactive elements.
  Uses official shadcn-vue patterns and avoids reinventing existing components.
---

# shadcn-vue Component First

## Core Principle

**ALWAYS check if shadcn-vue has a component for your use case before writing custom code.**

## Workflow

1. **Before implementing any UI feature:**
   - Check if shadcn-vue has a relevant component
   - Read the component documentation for all available props/features
   - Use `search-docs` with queries like "shadcn select multiple" or "shadcn combobox"

2. **Component location in project:**
   ```
   resources/shared/components/ui/
   ```

3. **If component doesn't exist locally:**
   - Check if it's available in shadcn-vue but not yet added to project
   - Consider adding it with `npx shadcn-vue@latest add <component>`

## shadcn-vue Documentation

Always use `search-docs` or `WebFetch` to get current component documentation:
- Base URL: https://www.shadcn-vue.com/docs/components/

## Common Components Reference

| Need | shadcn-vue Component |
|------|---------------------|
| Single select | Select |
| Multi-select | Select with `multiple` prop |
| Searchable select | Combobox |
| Date picker | Calendar + Popover |
| Confirmation | AlertDialog |
| Modal | Dialog |
| Slideover | Sheet |
| Dropdown menu | DropdownMenu |
| Context menu | ContextMenu |
| Tooltips | Tooltip |
| Notifications | Toast / Sonner |
| Form validation | Form (with vee-validate + zod) |
| Data table | Table (but we have custom DataTable) |
| Tabs | Tabs |
| Accordion | Accordion |
| Command palette | Command |
| Autocomplete | Combobox |

## Key Patterns

### Select with Multiple
```vue
<Select v-model="values" multiple>
  <SelectTrigger>
    <SelectValue placeholder="Select items" />
  </SelectTrigger>
  <SelectContent>
    <SelectItem v-for="item in items" :key="item.value" :value="item.value">
      {{ item.label }}
    </SelectItem>
  </SelectContent>
</Select>
```

### Combobox (Searchable)
```vue
<Combobox v-model="value">
  <ComboboxAnchor>
    <ComboboxInput placeholder="Search..." />
  </ComboboxAnchor>
  <ComboboxContent>
    <ComboboxItem v-for="item in items" :key="item.value" :value="item.value">
      {{ item.label }}
    </ComboboxItem>
  </ComboboxContent>
</Combobox>
```

## Anti-Patterns

- Writing custom checkbox lists when Select multiple exists
- Building custom modals when Dialog exists
- Creating custom dropdowns when DropdownMenu exists
- Implementing custom date pickers when Calendar exists
- Writing custom toasts when Sonner is available

## When Custom is OK

- When shadcn-vue doesn't have the component
- When the use case is highly specific and not covered by shadcn patterns
- When extending a shadcn component (create wrapper, don't rewrite)
