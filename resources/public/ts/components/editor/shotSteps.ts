import { $t } from '@public/ts/shared/i18n'

import type { Step } from './Steps.vue'

export type ShotStepLinks = {
  describe?: string
  storyline?: string
  keyframes?: string
  video?: string
}

/**
 * The four stages a shot goes through. A step gets a link when the shot has
 * reached it, so the director can move back and forth between finished steps.
 */
export const shotSteps = (links: ShotStepLinks = {}): Step[] => [
  { key: 'describe', title: $t('Describe'), href: links.describe },
  { key: 'storyline', title: $t('Storyline'), href: links.storyline },
  { key: 'keyframes', title: $t('Keyframes'), href: links.keyframes },
  { key: 'video', title: $t('Video'), href: links.video },
]
