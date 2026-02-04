import eslintPluginPrettierRecommended from 'eslint-plugin-prettier/recommended'
import simpleImportSort from 'eslint-plugin-simple-import-sort'
import pluginVue from 'eslint-plugin-vue'
import ts from 'typescript-eslint'

export default [
  ...ts.configs.recommended,
  ...pluginVue.configs['flat/recommended'],
  eslintPluginPrettierRecommended,
  {
    ignores: ['./resources/**/types/schema.d.ts'],
    languageOptions: {
      parserOptions: {
        parser: '@typescript-eslint/parser',
      },
    },
    plugins: {
      'simple-import-sort': simpleImportSort,
    },
    rules: {
      'no-undef': 'off',
      'no-bitwise': 'error',
      'no-console': 'error',
      'simple-import-sort/imports': 'error',
      'simple-import-sort/exports': 'error',
      'vue/arrow-spacing': 'error',
      'vue/block-lang': [
        'error',
        {
          script: {
            lang: 'ts',
          },
        },
      ],
      'vue/block-tag-newline': 'error',
      'vue/brace-style': 'error',
      'vue/html-button-has-type': ['error'],
      'vue/comma-dangle': ['error', 'always-multiline'],
      'vue/comma-spacing': [
        'error',
        {
          before: false,
          after: true,
        },
      ],
      'vue/block-order': [
        'error',
        {
          order: ['template', 'script', 'style'],
        },
      ],
      'vue/eqeqeq': 'error',
      'vue/func-call-spacing': ['error', 'never'],
      'vue/key-spacing': [
        'error',
        {
          beforeColon: false,
          afterColon: true,
        },
      ],
      'vue/keyword-spacing': [
        'error',
        {
          before: true,
          after: true,
        },
      ],
      'vue/multi-word-component-names': 'off',
      'vue/no-lone-template': 'error',
      'vue/no-template-target-blank': 'error',
      'vue/no-useless-concat': 'error',
      'vue/object-curly-spacing': ['error', 'always'],
      'vue/require-name-property': 'error',
      'vue/require-explicit-emits': 'error',
      'vue/prefer-template': 'error',
      'vue/quote-props': [
        'error',
        'consistent-as-needed',
        {
          keywords: true,
        },
      ],
      'vue/v-for-delimiter-style': 'error',
      '@typescript-eslint/consistent-type-imports': 'error',
      '@typescript-eslint/no-explicit-any': 'warn',
      '@typescript-eslint/no-import-type-side-effects': 'error',
      '@typescript-eslint/no-unused-vars': [
        'error',
        {
          vars: 'all',
          args: 'after-used',
          ignoreRestSiblings: false,
          argsIgnorePattern: '^_',
          varsIgnorePattern: '^_',
          caughtErrorsIgnorePattern: '^_',
        },
      ],
      'no-unused-vars': 'off',
    },
  },
]
