# Type System Skill

Manages the Scramble → OpenAPI → TypeScript type pipeline. Activates when working with TypeScript types, page props, form request types, Inertia type definitions, or any frontend type generation.

## Activation Triggers

- Working with `Inertia.Pages.*` or `Inertia.Requests.*` types
- Modifying page props or form request types in Vue components
- Adding/modifying Scramble extensions or OpenAPI schema generation
- Creating new Inertia pages or controllers that return Inertia responses
- Debugging type mismatches between PHP and TypeScript
- Any work involving `inertia.d.ts`, `schema.d.ts`, or `utils.d.ts`

## Architecture

```
Scramble (PHP) → /docs/{api}.json (OpenAPI)
    ↓
openapi-typescript → resources/{app}/ts/types/schema.d.ts
    ↓
type-generator plugin → resources/{app}/ts/types/inertia.d.ts
```

### Key Files

| File | Purpose |
|------|---------|
| `resources/vite/plugins/type-generator.ts` | Vite plugin: fetches OpenAPI, generates schema.d.ts + inertia.d.ts |
| `resources/admin/ts/types/inertia.d.ts` | Generated admin Inertia page + request types |
| `resources/public/ts/types/inertia.d.ts` | Generated public Inertia page + request types |
| `resources/admin/ts/types/utils.d.ts` | Re-exports `Inertia`, `PageProps`, and helper types |
| `resources/public/ts/types/utils.d.ts` | Same for public frontend |
| `app/Support/Scramble/Extensions/InertiaSharedDataExtension.php` | Merges HandleInertiaRequests shared data into page schemas |
| `app/Support/Scramble/Extensions/InertiaExtension.php` | Handles `Inertia::render()` responses |
| `app/Support/Scramble/Extensions/TableTypeInfer.php` | Maps `Table` subclasses to a named `TableResource` schema reference |
| `config/scramble.php` | Registers all Scramble extensions |

### How Types Are Generated

1. `openapi-typescript` fetches `/docs/{apiName}.json` → writes `schema.d.ts`
2. Type-generator reads `schema.d.ts`, parses the `operations` interface
3. For each operation:
   - Strip API prefix (e.g., `admin.`) → PascalCase segments → namespace path
   - GET 200 response `application/json` → `Inertia.Pages.*`
   - POST/PUT/PATCH requestBody `application/json` → `Inertia.Requests.*`
4. All `components["schemas"]["X"]` references are recursively inlined (circular refs → `Record<string, unknown>`)
5. Output written to `inertia.d.ts` formatted with prettier

### Shared Data (HandleInertiaRequests)

`InertiaSharedDataExtension` uses Scramble's Infer engine to analyze the `share()` method on each route's Inertia middleware. It:
- Finds the Inertia middleware class from route middleware
- Infers the return type of `share()` (requires array spread syntax, NOT `array_merge()`)
- Unwraps closures (Inertia resolves them at runtime)
- Merges shared properties into each page's 200 response schema

Shared data keys are stripped on the frontend via `PageProps<T> = Omit<T, keyof SharedData>` in `utils.d.ts`, deriving keys from `shared.d.ts`.

### Operation Name → Namespace Mapping

Operation names like `admin.accounts.update.store` map to:
- Strip prefix: `accounts.update.store`
- PascalCase: `Accounts.Update.Store`
- Strip trailing `_N` suffixes (duplicate operation dedup)

## Usage in Vue Components

### Page Props
```typescript
import type { Inertia, PageProps } from '@admin:types/utils'

defineProps<PageProps<Inertia.Pages.Accounts.Update>>()
```

### Form Requests
```typescript
import type { Inertia } from '@admin:types/utils'

const form = useForm<Inertia.Requests.Accounts.Store>({
  name: '',
  email: '',
  roles: [],
})
```

### Schema Types (for API client usage)
```typescript
import type { components, operations } from '@admin:types/schema'
```

## When Adding New Pages

1. Create the controller with `Inertia::render()` — Scramble auto-discovers it
2. The operation name comes from the route name (e.g., `admin.accounts.view`)
3. Restart vite dev server or wait for schema refresh → types appear in `inertia.d.ts`
4. Use `PageProps<Inertia.Pages.{Namespace}.{Action}>` in the Vue component

## When Adding Request Types

1. Create a FormRequest class with validation rules
2. Type-hint it in the controller method — Scramble infers the request body schema
3. Types appear under `Inertia.Requests.{Namespace}.{Action}`

## Regenerating Types

Types regenerate automatically when:
- Vite dev server starts (`configureServer` hook)
- `schema.d.ts` file changes (file watcher)
- PHP files in `app/` change (debounced watcher re-fetches OpenAPI schema)

To manually regenerate: restart the vite dev server (`yarn run dev`).

## Route Naming

The type generator filters operations by `apiName.` prefix (e.g., `admin.`). Routes MUST be named so Scramble generates prefixed operation IDs. Unnamed routes get non-prefixed IDs and are excluded from type generation. Auth routes get the `admin.auth.` prefix via the route group in `routes/web.php`.

## External Type Imports (`typeImports`)

The `typeImports` option maps schema names to external TypeScript types instead of inlining them:
```typescript
typeGenerator({
  typeImports: {
    TableResource: { type: 'TableResource', from: '@shared:ui/data-table' },
  },
})
```

## Debugging

- Check `/docs/admin.json` or `/docs/public.json` for the raw OpenAPI schema
- Verify operation names match expected namespace paths
- Run `npx vue-tsc -p tsconfig.json --noEmit` to validate TypeScript
- If shared data is missing: ensure middleware uses array spread (`[...parent::share($request), ...]`), not `array_merge()`
