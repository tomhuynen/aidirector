import * as icons from 'lucide-vue-next'
import type { Component } from 'vue'

/**
 * Composable for resolving icon names to lucide-vue-next components.
 * Supports both kebab-case (e.g., 'pencil-line') and PascalCase (e.g., 'PencilLine').
 */
export function useIcons() {
  /**
   * Convert icon name to lucide-vue-next component.
   * @param iconName - Icon name in kebab-case or PascalCase
   * @returns The icon component or null if not found
   */
  const getIconComponent = (iconName?: string): Component | null => {
    if (!iconName) return null

    // Convert kebab-case to PascalCase (e.g., 'pencil-line' -> 'PencilLine')
    const pascalCase = iconName
      .split('-')
      .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
      .join('')

    return (icons as unknown as Record<string, Component>)[pascalCase] ?? null
  }

  return {
    getIconComponent,
  }
}
