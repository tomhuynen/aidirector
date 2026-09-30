import { access, readFile, writeFile } from 'node:fs/promises'
import { dirname, resolve } from 'node:path'
import { promisify } from 'node:util'

import { exec } from 'child_process'
import prettier from 'prettier'
import type { Plugin } from 'vite'
import { loadEnv } from 'vite'

const execAsync = promisify(exec)

type TypeImport = { type: string; from: string }

type TypeGeneratorOptions = {
  outputPath: string
  apiName?: string
  typeImports?: Record<string, TypeImport>
}

export function typeGenerator(options: TypeGeneratorOptions): Plugin {
  return {
    name: 'type-generator',
    async configureServer(server) {
      const env = loadEnv(server.config.mode, process.cwd())
      const appUrl = env.VITE_APP_URL
      if (!appUrl) return

      const apiName = options.apiName || 'api'
      const inertiaPath = resolve(dirname(options.outputPath), 'inertia.d.ts')

      // A failing fetch (for example a 500 from a half-edited route file) must
      // not take the dev server down; the previous schema stays in place.
      const fetchSchema = async () => {
        try {
          const { stdout, stderr } = await execAsync(
            `npx openapi-typescript ${appUrl}/docs/${apiName}.json -o ${options.outputPath}`,
            { env: { ...process.env, NODE_TLS_REJECT_UNAUTHORIZED: '0' } },
          )

          if (stdout) {
            // eslint-disable-next-line no-console
            console.log(stdout)
          }
          if (stderr) {
            // eslint-disable-next-line no-console
            console.error(stderr)
          }
        } catch (error) {
          // eslint-disable-next-line no-console
          console.warn(
            `[type-generator] Could not fetch /docs/${apiName}.json, keeping existing schema:`,
            (error as Error).message,
          )
        }
      }

      await fetchSchema()

      const exists = await access(options.outputPath)
        .then(() => true)
        .catch(() => false)

      if (exists) {
        await generateInertiaTypes(options.outputPath, inertiaPath, apiName, options.typeImports)
      }

      // Re-generate inertia.d.ts when schema.d.ts changes. The write by
      // openapi-typescript can trigger several change events while the file
      // is still partial, so wait for the writes to settle first.
      let schemaTimer: ReturnType<typeof setTimeout>
      server.watcher.add(options.outputPath)
      server.watcher.on('change', (changedPath) => {
        if (changedPath !== options.outputPath) return

        clearTimeout(schemaTimer)
        schemaTimer = setTimeout(async () => {
          await generateInertiaTypes(options.outputPath, inertiaPath, apiName, options.typeImports)
        }, 300)
      })

      // Re-fetch schema when PHP files change
      let debounceTimer: ReturnType<typeof setTimeout>
      server.watcher.add(resolve(process.cwd(), 'app'))
      server.watcher.on('change', (changedPath) => {
        if (!changedPath.endsWith('.php')) return

        clearTimeout(debounceTimer)
        debounceTimer = setTimeout(async () => {
          // eslint-disable-next-line no-console
          console.log(`[type-generator] PHP change detected, refreshing schema...`)
          await fetchSchema()
        }, 1000)
      })
    },
  }
}

type OperationTypes = { responseType: string | null; requestType: string | null }

async function generateInertiaTypes(
  schemaPath: string,
  outputPath: string,
  apiName: string,
  typeImports?: Record<string, TypeImport>,
): Promise<void> {
  try {
    const [schema, prettierConfig] = await Promise.all([
      readFile(schemaPath, 'utf-8'),
      prettier.resolveConfig(outputPath),
    ])

    const operations = parseOperations(schema)

    // A schema without operations is a partial or failed write; keep the
    // previous inertia.d.ts instead of replacing it with an empty one.
    if (operations.size === 0) {
      // eslint-disable-next-line no-console
      console.warn('[type-generator] Schema has no operations, keeping existing types')
      return
    }

    const prefix = apiName + '.'
    const pages = new Map<string, string>()
    const requests = new Map<string, string>()
    const usedImports = new Set<string>()

    for (const [name, op] of operations) {
      if (!name.startsWith(prefix)) continue

      const path = name.slice(prefix.length)
      const nsPath = toNamespacePath(path)
      if (!nsPath) continue

      if (op.responseType && isObjectType(op.responseType)) {
        const inlined = inlineSchemaRefs(op.responseType, schema, new Set(), typeImports, usedImports)
        pages.set(nsPath, stripComments(inlined))
      }

      if (op.requestType) {
        const inlined = inlineSchemaRefs(op.requestType, schema, new Set(), typeImports, usedImports)
        requests.set(nsPath, stripComments(inlined))
      }
    }

    const content = buildOutput(pages, requests, typeImports, usedImports)
    const formatted = await prettier.format(content, { ...prettierConfig, filepath: outputPath })
    await writeFile(outputPath, formatted)

    // eslint-disable-next-line no-console
    console.log(`[type-generator] Inertia: ${pages.size} pages, ${requests.size} requests`)
  } catch (error) {
    // eslint-disable-next-line no-console
    console.error('[type-generator] Failed:', error)
  }
}

// --- Parsing ---

function parseOperations(content: string): Map<string, OperationTypes> {
  const ops = new Map<string, OperationTypes>()

  const opsIdx = content.indexOf('export interface operations {')
  if (opsIdx === -1) return ops

  const opsBlockStart = opsIdx + 'export interface operations '.length
  const opsBlockEnd = findClosingBrace(content, opsBlockStart)
  if (opsBlockEnd === -1) return ops

  const opsBlock = content.substring(opsBlockStart, opsBlockEnd + 1)

  const opPattern = /\n {4}"([^"]+)":\s*\{/g
  let match
  while ((match = opPattern.exec(opsBlock)) !== null) {
    const name = match[1]
    const blockStart = match.index + match[0].length - 1
    const blockEnd = findClosingBrace(opsBlock, blockStart)
    if (blockEnd === -1) continue

    const block = opsBlock.substring(blockStart, blockEnd + 1)

    if (!ops.has(name)) {
      ops.set(name, {
        responseType: extractResponseType(block),
        requestType: extractRequestBodyType(block),
      })
    }

    opPattern.lastIndex = blockEnd + 1
  }

  return ops
}

function extractResponseType(block: string): string | null {
  const twoHundredMatch = block.match(/\b200:\s*\{/)
  if (!twoHundredMatch || twoHundredMatch.index === undefined) return null

  const twoHundredStart = twoHundredMatch.index + twoHundredMatch[0].length - 1
  const twoHundredEnd = findClosingBrace(block, twoHundredStart)
  if (twoHundredEnd === -1) return null

  const twoHundredBlock = block.substring(twoHundredStart, twoHundredEnd + 1)

  return extractContentType(twoHundredBlock)
}

function extractRequestBodyType(block: string): string | null {
  if (block.includes('requestBody?: never') || !block.includes('requestBody')) return null

  const rbMatch = block.match(/requestBody\??:\s*\{/)
  if (!rbMatch || rbMatch.index === undefined) return null

  const rbStart = rbMatch.index + rbMatch[0].length - 1
  const rbEnd = findClosingBrace(block, rbStart)
  if (rbEnd === -1) return null

  const rbBlock = block.substring(rbStart, rbEnd + 1)

  return extractContentType(rbBlock)
}

function extractContentType(block: string): string | null {
  // Try application/json first, then multipart/form-data
  const contentTypes = ['"application/json": ', '"multipart/form-data": ']

  for (const marker of contentTypes) {
    const idx = block.indexOf(marker)
    if (idx === -1) continue

    const valueStart = idx + marker.length

    if (block[valueStart] === '{') {
      const end = findClosingBrace(block, valueStart)
      if (end === -1) continue
      return block.substring(valueStart, end + 1)
    }

    // For non-object types (references, intersections), find the end properly
    // by tracking brace depth to handle `Foo & { bar: string }`
    let depth = 0
    let end = valueStart
    while (end < block.length) {
      const char = block[end]
      if (char === '{') depth++
      else if (char === '}') depth--
      else if (char === ';' && depth === 0) break
      end++
    }
    if (end === block.length) continue
    return block.substring(valueStart, end).trim()
  }

  return null
}

// --- Schema inlining ---

function extractSchemaType(content: string, name: string): string {
  const pattern = `\n        ${name}: `
  const idx = content.indexOf(pattern)
  if (idx === -1) return 'unknown'

  const valueStart = idx + pattern.length

  if (content[valueStart] === '{') {
    const end = findClosingBrace(content, valueStart)
    if (end === -1) return 'unknown'
    return content.substring(valueStart, end + 1)
  }

  const semicolon = content.indexOf(';', valueStart)
  if (semicolon === -1) return 'unknown'
  return content.substring(valueStart, semicolon).trim()
}

function inlineSchemaRefs(
  type: string,
  fullContent: string,
  ancestors: Set<string>,
  typeImports?: Record<string, TypeImport>,
  usedImports?: Set<string>,
): string {
  return type.replace(/components\["schemas"\]\["(\w+)"\]/g, (_, name: string) => {
    if (typeImports?.[name]) {
      usedImports?.add(name)
      return typeImports[name].type
    }
    if (ancestors.has(name)) return 'Record<string, unknown>'
    const newAncestors = new Set(ancestors)
    newAncestors.add(name)
    const schemaType = extractSchemaType(fullContent, name)
    return inlineSchemaRefs(schemaType, fullContent, newAncestors, typeImports, usedImports)
  })
}

// --- Helpers ---

function findClosingBrace(content: string, start: number): number {
  let depth = 0
  for (let i = start; i < content.length; i++) {
    if (content[i] === '{') depth++
    else if (content[i] === '}') {
      depth--
      if (depth === 0) return i
    }
  }
  return -1
}

function isObjectType(type: string): boolean {
  const trimmed = type.trim()
  return trimmed.startsWith('{') || trimmed.startsWith('Record<')
}

function stripComments(type: string): string {
  return type.replace(/\/\*\*[^]*?\*\/\s*/g, '')
}

function toNamespacePath(path: string): string | null {
  const cleaned = path.replace(/_\d+$/, '')
  const segments = cleaned.split('.')
  if (segments.some((s) => !s)) return null
  return segments.map((s) => toPascalCase(s)).join('.')
}

function toPascalCase(str: string): string {
  return str
    .split('-')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')
}

// --- Output ---

type NamespaceNode = {
  types: Map<string, string>
  children: Map<string, NamespaceNode>
}

function buildTree(entries: Map<string, string>): NamespaceNode {
  const root: NamespaceNode = { types: new Map(), children: new Map() }

  for (const [path, typeBody] of entries) {
    const segments = path.split('.')
    const typeName = segments.pop()!
    let current = root
    for (const seg of segments) {
      if (!current.children.has(seg)) {
        current.children.set(seg, { types: new Map(), children: new Map() })
      }
      current = current.children.get(seg)!
    }
    current.types.set(typeName, typeBody)
  }

  return root
}

function renderTree(node: NamespaceNode, indent: string): string {
  let out = ''

  for (const [name, body] of node.types) {
    out += `${indent}export type ${name} = ${body}\n`
  }

  for (const [name, child] of node.children) {
    out += `${indent}export namespace ${name} {\n`
    out += renderTree(child, indent + '  ')
    out += `${indent}}\n`
  }

  return out
}

function buildOutput(
  pages: Map<string, string>,
  requests: Map<string, string>,
  typeImports?: Record<string, TypeImport>,
  usedImports?: Set<string>,
): string {
  const pagesTree = buildTree(pages)
  const requestsTree = buildTree(requests)

  let out = `/**
 * Inertia page and request types.
 * Auto-generated by type-generator plugin from Scramble's OpenAPI schema.
 */\n`

  if (typeImports && usedImports) {
    const grouped = new Map<string, string[]>()
    for (const name of usedImports) {
      const imp = typeImports[name]
      if (!grouped.has(imp.from)) grouped.set(imp.from, [])
      grouped.get(imp.from)!.push(imp.type)
    }
    for (const [from, types] of grouped) {
      out += `import type { ${types.join(', ')} } from '${from}'\n`
    }
  }

  out += `
export namespace Inertia {
export namespace Pages {
${renderTree(pagesTree, '')}
}
`

  if (requests.size > 0) {
    out += `export namespace Requests {
${renderTree(requestsTree, '')}
}
`
  }

  out += `}\n`

  return out
}
