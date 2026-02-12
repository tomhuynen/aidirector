import { access, readFile, writeFile } from 'node:fs/promises'
import { promisify } from 'node:util'

import { exec } from 'child_process'
import prettier from 'prettier'
import type { Plugin } from 'vite'
import { loadEnv } from 'vite'

const execAsync = promisify(exec)

type TypeGeneratorOptions = {
  outputPath: string
  wayfinderTypesPath?: string
  bridgeOutputPath?: string
  apiName?: string
  /** Controller path prefixes to include (e.g., ['Admin', 'Auth'] or ['Public', '_root']). */
  controllerPrefixes?: string[]
}

type ResourceRef = { fullPath: string; className: string }
type PageOverride = { namespace: string; typeName: string; properties: Property[] }
type Property = { name: string; type: string }
type PageType = { namespace: string; typeName: string }

export function typeGenerator(options: TypeGeneratorOptions): Plugin {
  return {
    name: 'type-generator',
    async configureServer(server) {
      const env = loadEnv(server.config.mode, process.cwd())
      const appUrl = env.VITE_APP_URL
      if (!appUrl) return

      const apiName = options.apiName || 'api'
      const { stdout, stderr } = await execAsync(
        `npx openapi-typescript ${appUrl}/docs/${apiName}.json -o ${options.outputPath}`,
      )

      if (stdout) {
        // eslint-disable-next-line no-console
        console.log(stdout)
      }
      if (stderr) {
        // eslint-disable-next-line no-console
        console.error(stderr)
      }

      if (options.wayfinderTypesPath) {
        const filesExist = await Promise.all([
          access(options.outputPath)
            .then(() => true)
            .catch(() => false),
          access(options.wayfinderTypesPath)
            .then(() => true)
            .catch(() => false),
        ])

        const bridgePath = options.bridgeOutputPath || options.outputPath.replace(/\.d\.ts$/, '-bridge.d.ts')

        if (filesExist.every(Boolean)) {
          await generateBridge(options.outputPath, options.wayfinderTypesPath, bridgePath, options.controllerPrefixes)
        } else {
          // eslint-disable-next-line no-console
          console.log('[type-generator] Skipping bridge generation (waiting for dependencies to be generated)')
        }

        // Watch for wayfinder types changes to regenerate bridge
        server.watcher.add(options.wayfinderTypesPath)
        server.watcher.on('change', async (changedPath) => {
          if (changedPath === options.wayfinderTypesPath) {
            await generateBridge(
              options.outputPath,
              options.wayfinderTypesPath!,
              bridgePath,
              options.controllerPrefixes,
            )
          }
        })
      }
    },
  }
}

async function generateBridge(
  schemaPath: string,
  wayfinderPath: string,
  outputPath: string,
  controllerPrefixes?: string[],
): Promise<void> {
  try {
    const [wayfinder, schema, prettierConfig] = await Promise.all([
      readFile(wayfinderPath, 'utf-8'),
      readFile(schemaPath, 'utf-8'),
      prettier.resolveConfig(outputPath),
    ])

    const resources = extractResources(wayfinder, controllerPrefixes)
    const schemas = extractSchemas(schema)
    const overrides = extractOverrides(wayfinder, schema)
    const pages = extractPages(wayfinder, controllerPrefixes)

    const content = buildBridgeContent(resources, schemas, overrides, pages)
    const formatted = await prettier.format(content, { ...prettierConfig, filepath: outputPath })
    await writeFile(outputPath, formatted)

    // eslint-disable-next-line no-console
    console.log(`[type-generator] Bridge: ${resources.length} resources, ${overrides.length} overrides`)
  } catch (error) {
    // eslint-disable-next-line no-console
    console.error('[type-generator] Failed:', error)
  }
}

function extractResources(content: string, controllerPrefixes?: string[]): ResourceRef[] {
  const matches = content.match(/App\.Http\.Resources\.[A-Za-z.]+/g) || []
  return [...new Set(matches)]
    .filter((fullPath) => {
      if (!controllerPrefixes) return true
      // Extract resource prefix (e.g., 'Admin' from 'App.Http.Resources.Admin.UserResource')
      const parts = fullPath.split('.')
      if (parts.length < 5) return false
      const resourcePrefix = parts[3]
      return controllerPrefixes.includes(resourcePrefix)
    })
    .map((fullPath) => ({
      fullPath,
      className: fullPath.split('.').pop()!,
    }))
}

function extractSchemas(content: string): Set<string> {
  const schemas = new Set<string>()
  for (const [, name] of content.matchAll(/^\s+([A-Za-z]+Resource): \{$/gm)) {
    schemas.add(name)
  }
  return schemas
}

function extractOverrides(wayfinder: string, schema: string): PageOverride[] {
  const pattern = /@see\s*\[\\([^\]]+)\][^\n]*\n[^]*?export type (\w+) = Inertia\.SharedData & \{([^}]+)\}/g
  const overrides: PageOverride[] = []

  for (const [, controller, typeName, propsBlock] of wayfinder.matchAll(pattern)) {
    const needsFix = propsBlock.includes('AnonymousResourceCollection') || /:\s*unknown\b/.test(propsBlock)
    if (!needsFix) continue

    const nsMatch = controller.match(/App\\Http\\Controllers\\Admin\\(\w+)\\/)
    if (!nsMatch) continue

    const operationName = controllerToOperation(controller)
    const scrambleProps = extractScrambleProps(schema, operationName)
    if (!scrambleProps) continue

    const properties = extractPropertiesToFix(propsBlock, scrambleProps)
    if (properties.length > 0) {
      overrides.push({ namespace: nsMatch[1], typeName, properties })
    }
  }

  return overrides
}

function extractPropertiesToFix(propsBlock: string, scrambleProps: Record<string, string>): Property[] {
  const properties: Property[] = []
  const patterns = [/(\w+):\s*Illuminate\.Http\.Resources\.Json\.AnonymousResourceCollection/g, /(\w+):\s*unknown\b/g]

  for (const pattern of patterns) {
    for (const [, name] of propsBlock.matchAll(pattern)) {
      if (scrambleProps[name]) {
        properties.push({ name, type: scrambleProps[name] })
      }
    }
  }

  return properties
}

function controllerToOperation(ref: string): string {
  const match = ref.match(/App\\Http\\Controllers\\Admin\\(\w+)\\(\w+)Controller::(\w+)/)
  if (!match) return ''

  const [, resource, controller, method] = match
  const r = resource.toLowerCase()
  const c = controller.toLowerCase()

  if (r === 'system') return `admin.system.${c}`
  if (r === 'settings') return `admin.settings.${c}.${method}`

  const crudMethods = ['index', 'view', 'update', 'create']
  if (crudMethods.includes(c) && c === method) return `admin.${r}.${method}`

  return `admin.${r}.${method}`
}

function extractScrambleProps(schema: string, operation: string): Record<string, string> | null {
  const escaped = operation.replace(/\./g, '\\.')
  const pattern = new RegExp(
    `"${escaped}":\\s*\\{[^]*?responses:\\s*\\{\\s*200:\\s*\\{[^]*?content:\\s*\\{\\s*"application/json":\\s*\\{([\\s\\S]*?)\\n\\s{20}\\}`,
    'm',
  )

  const match = schema.match(pattern)
  if (!match) return null

  const props: Record<string, string> = {}
  const block = match[1]

  for (const [, name, schemaName] of block.matchAll(/(\w+):\s*components\["schemas"\]\["(\w+)"\]\[\]/g)) {
    props[name] = `components['schemas']['${schemaName}'][]`
  }

  for (const [, name, schemaName] of block.matchAll(/(\w+):\s*components\["schemas"\]\["(\w+)"\](?!\[)/g)) {
    props[name] = `components['schemas']['${schemaName}']`
  }

  for (const [, name] of block.matchAll(/^ {24}(\w+):\s*\{/gm)) {
    if (!props[name]) {
      props[name] = `operations['${operation}']['responses'][200]['content']['application/json']['${name}']`
    }
  }

  return props
}

function extractPages(content: string, controllerPrefixes?: string[]): PageType[] {
  const start = content.indexOf('export namespace Pages {')
  const end = content.indexOf('\nexport namespace Laravel {')
  if (start === -1 || end === -1) return []

  const pages: PageType[] = []
  const namespaceStack: string[] = []
  let lastControllerPrefix: string | null = null

  for (const line of content.substring(start, end).split('\n')) {
    // Track controller prefix from @see comments
    const seeMatch = line.match(/@see\s*\[\\App\\Http\\Controllers\\([A-Za-z]+)(?:\\|::)/)
    if (seeMatch) {
      const firstPart = seeMatch[1]
      // If it ends with 'Controller', it's a root-level controller (no prefix)
      lastControllerPrefix = firstPart.endsWith('Controller') ? null : firstPart
    }

    const nsMatch = line.match(/export namespace (\w+) \{/)
    if (nsMatch && !['Pages', 'Inertia'].includes(nsMatch[1])) {
      namespaceStack.push(nsMatch[1])
    }

    if (/^\s*\}\s*$/.test(line) && namespaceStack.length > 0) {
      namespaceStack.pop()
    }

    const typeMatch = line.match(/export type (\w+) = /)
    if (typeMatch) {
      // Filter by controller prefix if specified
      if (controllerPrefixes) {
        const matches = lastControllerPrefix
          ? controllerPrefixes.includes(lastControllerPrefix)
          : controllerPrefixes.includes('_root')
        if (!matches) continue
      }

      const namespace = namespaceStack.length > 0 ? namespaceStack[namespaceStack.length - 1] : '_root'
      pages.push({ namespace, typeName: typeMatch[1] })
    }
  }

  return pages
}

function buildBridgeContent(
  resources: ResourceRef[],
  schemas: Set<string>,
  overrides: PageOverride[],
  pages: PageType[],
): string {
  const needsOperations = overrides.some((o) => o.properties.some((p) => p.type.startsWith("operations['")))
  const imports = needsOperations ? 'components, operations' : 'components'

  const resourceGroups = groupBy(resources, (r) => r.fullPath.split('.').slice(0, -1).join('.'))
  const overrideMap = new Map(overrides.map((o) => [`${o.namespace}.${o.typeName}`, o]))
  const pageGroups = groupBy(pages, (p) => p.namespace)

  return `/**
 * Type bridge - single import point for Wayfinder + Scramble types.
 * Re-exports Wayfinder types and provides corrected versions where needed.
 * Auto-generated by type-generator plugin.
 */
import type { ${imports} } from './schema'
import type { Inertia as WayfinderInertia } from '@wayfinder/types'

export type { components, paths, operations } from './schema'
export type { App } from '@wayfinder/types'
export type PageProps<T> = Omit<T, 'app' | 'isImpersonated' | 'page'>

declare module '@wayfinder/types' {
${buildResourceNamespaces(resourceGroups, schemas)}
${buildIlluminateTypes()}
}

export namespace Inertia {
export type SharedData = WayfinderInertia.SharedData
export namespace Pages {
${buildPageTypes(pageGroups, overrideMap)}
}
}
`
}

function buildResourceNamespaces(groups: Map<string, ResourceRef[]>, schemas: Set<string>): string {
  let content = ''
  for (const [path, refs] of groups) {
    const parts = path.split('.')
    content += parts.map((p) => `export namespace ${p} {`).join(' ') + '\n'
    for (const { className } of refs) {
      content += schemas.has(className)
        ? `export type ${className} = components['schemas']['${className}']\n`
        : `export type ${className} = Record<string, unknown>\n`
    }
    content += '}'.repeat(parts.length) + '\n'
  }
  return content
}

function buildIlluminateTypes(): string {
  return `export namespace Illuminate {
export namespace Http { export namespace Resources { export namespace Json {
export type AnonymousResourceCollection<T = unknown> = { data: T[] }
}}}
export namespace Database { export namespace Eloquent { export namespace Casts {
export type Attribute<TGet = unknown, TSet = unknown> = TGet
}}}
export namespace Bus {
export type Batch = { id: string; name: string; totalJobs: number; pendingJobs: number; failedJobs: number }
}
export namespace Notifications {
export type DatabaseNotification = { id: string; type: string; data: Record<string, unknown>; read_at: string | null; created_at: string }
}
}`
}

function buildPageTypes(groups: Map<string, PageType[]>, overrides: Map<string, PageOverride>): string {
  let content = ''
  for (const [namespace, types] of groups) {
    const isRoot = namespace === '_root'

    if (!isRoot) {
      content += `export namespace ${namespace} {\n`
    }

    for (const { typeName } of types) {
      const override = overrides.get(`${namespace}.${typeName}`)
      const wayfinderPath = isRoot
        ? `WayfinderInertia.Pages.${typeName}`
        : `WayfinderInertia.Pages.${namespace}.${typeName}`

      if (override) {
        const omit = override.properties.map((p) => `'${p.name}'`).join(' | ')
        const props = override.properties.map((p) => `${p.name}: ${p.type}`).join('; ')
        content += `export type ${typeName} = Omit<${wayfinderPath}, ${omit}> & { ${props} }\n`
      } else {
        content += `export type ${typeName} = ${wayfinderPath}\n`
      }
    }

    if (!isRoot) {
      content += '}\n'
    }
  }
  return content
}

function groupBy<T>(items: T[], keyFn: (item: T) => string): Map<string, T[]> {
  const map = new Map<string, T[]>()
  for (const item of items) {
    const key = keyFn(item)
    if (!map.has(key)) map.set(key, [])
    map.get(key)!.push(item)
  }
  return map
}
