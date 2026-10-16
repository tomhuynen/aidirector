import DestroyController from './DestroyController'
import PickController from './PickController'
import RoundController from './RoundController'
import StoreController from './StoreController'
import UpdateController from './UpdateController'
import VersionController from './VersionController'
import ViewController from './ViewController'

const Elements = {
  ViewController: Object.assign(ViewController, ViewController),
  StoreController: Object.assign(StoreController, StoreController),
  UpdateController: Object.assign(UpdateController, UpdateController),
  VersionController: Object.assign(VersionController, VersionController),
  DestroyController: Object.assign(DestroyController, DestroyController),
  RoundController: Object.assign(RoundController, RoundController),
  PickController: Object.assign(PickController, PickController),
}

export default Elements
