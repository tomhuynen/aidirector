import IndexController from './IndexController'
import ViewController from './ViewController'

const Activities = {
  IndexController: Object.assign(IndexController, IndexController),
  ViewController: Object.assign(ViewController, ViewController),
}

export default Activities
