import { promisify } from 'node:util'

import { exec } from 'child_process'
import type { Plugin } from 'vite'
import { loadEnv } from 'vite'

const execAsync = promisify(exec)

type TypeGeneratorOptions = {
  outputPath: string
  /**
   * The name of the API to generate types for.
   * This will result in the endpoint being fetched from `${appUrl}/docs/${apiName}.json`.
   *
   * @default 'api'
   */
  apiName?: string
}

export function typeGenerator(options: TypeGeneratorOptions): Plugin {
  return {
    name: 'type-generator',
    async configureServer(server) {
      const env = loadEnv(server.config.mode, process.cwd())
      const appUrl = env.VITE_APP_URL

      if (!appUrl) {
        return
      }

      const apiName = options.apiName || 'api'

      const { stdout, stderr } = await execAsync(
        `npx openapi-typescript ${appUrl}/docs/${apiName}.json -o ${options.outputPath}`,
      )

      // eslint-disable-next-line no-console
      console.log(stdout)

      if (stderr) {
        // eslint-disable-next-line no-console
        console.error(stderr)
      }
    },
  }
}
