import DestroyController from './DestroyController'
import IndexController from './IndexController'
import SwitchController from './SwitchController'
import UpdateController from './UpdateController'
import ViewController from './ViewController'

const Tenants = {
  IndexController: Object.assign(IndexController, IndexController),
  UpdateController: Object.assign(UpdateController, UpdateController),
  ViewController: Object.assign(ViewController, ViewController),
  DestroyController: Object.assign(DestroyController, DestroyController),
  SwitchController: Object.assign(SwitchController, SwitchController),
}

export default Tenants
