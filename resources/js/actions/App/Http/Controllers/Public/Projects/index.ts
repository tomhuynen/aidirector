import Branding from './Branding'
import ChatController from './ChatController'
import CoverController from './CoverController'
import CreateController from './CreateController'
import DestroyController from './DestroyController'
import Elements from './Elements'
import FormatController from './FormatController'
import IndexController from './IndexController'
import RuleController from './RuleController'
import Style from './Style'
import ViewController from './ViewController'
import VoiceOverController from './VoiceOverController'

const Projects = {
  IndexController: Object.assign(IndexController, IndexController),
  CreateController: Object.assign(CreateController, CreateController),
  ChatController: Object.assign(ChatController, ChatController),
  ViewController: Object.assign(ViewController, ViewController),
  CoverController: Object.assign(CoverController, CoverController),
  FormatController: Object.assign(FormatController, FormatController),
  VoiceOverController: Object.assign(VoiceOverController, VoiceOverController),
  RuleController: Object.assign(RuleController, RuleController),
  DestroyController: Object.assign(DestroyController, DestroyController),
  Branding: Object.assign(Branding, Branding),
  Elements: Object.assign(Elements, Elements),
  Style: Object.assign(Style, Style),
}

export default Projects
