import { i18nVue, loadLanguageAsync, trans } from 'laravel-vue-i18n'

const options = {
  fallbackLang: 'en',
  resolve: async (lang: string) => {
    const langs = import.meta.glob('../../locale/*.json')

    return await langs[`../../locale/${lang}.json`]()
  },
}

export { trans as $t, i18nVue, loadLanguageAsync, options, trans }
