import { setIconResolver } from '@inertiaui/table-vue'
import * as icons from 'lucide-vue-next'

const resolve = (name: string) => icons[name as keyof typeof icons]

setIconResolver((icon) => resolve(icon))

export function useIcons() {
  return {
    resolve,
  }
}
