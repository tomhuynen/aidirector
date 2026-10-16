import DestroyController from './DestroyController'
import IndexController from './IndexController'
import InviteController from './InviteController'
import UpdateController from './UpdateController'
import ViewController from './ViewController'

const Accounts = {
  UpdateController: Object.assign(UpdateController, UpdateController),
  InviteController: Object.assign(InviteController, InviteController),
  ViewController: Object.assign(ViewController, ViewController),
  IndexController: Object.assign(IndexController, IndexController),
  DestroyController: Object.assign(DestroyController, DestroyController),
}

export default Accounts
