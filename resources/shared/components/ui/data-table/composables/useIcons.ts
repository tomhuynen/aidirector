import * as icons from 'lucide-vue-next'
import type { Component } from 'vue'

type IconResolver = (name: string) => Component | null

let customResolver: IconResolver | null = null

export function setIconResolver(resolver: IconResolver): void {
  customResolver = resolver
}

function defaultResolver(iconName: string): Component | null {
  const pascalCase = iconName
    .split('-')
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('')

  return (icons as unknown as Record<string, Component>)[pascalCase] ?? null
}

export function useIcons() {
  const getIconComponent = (iconName?: string): Component | null => {
    if (!iconName) return null

    if (customResolver) {
      const resolved = customResolver(iconName)
      if (resolved) return resolved
    }

    return defaultResolver(iconName)
  }

  return {
    getIconComponent,
  }
}
