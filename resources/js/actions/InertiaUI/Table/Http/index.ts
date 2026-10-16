import ActionController from './ActionController'
import ExportController from './ExportController'
import ViewController from './ViewController'

const Http = {
  ActionController: Object.assign(ActionController, ActionController),
  ExportController: Object.assign(ExportController, ExportController),
  ViewController: Object.assign(ViewController, ViewController),
}

export default Http
