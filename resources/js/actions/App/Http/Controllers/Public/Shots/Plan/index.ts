import ChangeController from './ChangeController'
import DirectorController from './DirectorController'
import ElementsController from './ElementsController'
import VoiceOverController from './VoiceOverController'

const Plan = {
  DirectorController: Object.assign(DirectorController, DirectorController),
  ChangeController: Object.assign(ChangeController, ChangeController),
  VoiceOverController: Object.assign(VoiceOverController, VoiceOverController),
  ElementsController: Object.assign(ElementsController, ElementsController),
}

export default Plan
