import path from 'node:path'

const buildEslintCommand = (filenames) =>
  `eslint --fix ${filenames.map((f) => path.relative(process.cwd(), f)).join(' ')}`

const buildTscCommand = () => `vue-tsc -p tsconfig.json --noEmit`

const buildPrettierCommand = (filenames) =>
  `prettier --write ${filenames.map((f) => path.relative(process.cwd(), f)).join(' ')}`

const prefixWithHerd = (command) => {
  const envPath = process.env.PATH

  if (!envPath) {
    throw new Error('PATH is not set in env variables')
  }

  return envPath.includes('/Herd/') || envPath.includes('/herd/') ? `herd php ${command}` : command
}

const buildPhpLintCommand = () => prefixWithHerd('./vendor/bin/phplint ./app --no-interaction --no-cache')
const buildPintCommand = () => prefixWithHerd('./vendor/bin/pint')
const buildPhpStanCommand = () => prefixWithHerd('./vendor/bin/phpstan analyse --memory-limit=2G')
const buildComposerDepAnalyserCommand = () => prefixWithHerd('./vendor/bin/composer-dependency-analyser')
const buildComposerCommand = () => 'composer validate --no-check-publish --strict'

export default {
  '*.{js,ts,vue}': [buildTscCommand, buildEslintCommand],
  '*.{css,yaml}': [buildPrettierCommand],
  '*.php': [buildPhpLintCommand, buildPintCommand, buildPhpStanCommand, buildComposerDepAnalyserCommand],
  'composer.json': [buildComposerCommand],
}
