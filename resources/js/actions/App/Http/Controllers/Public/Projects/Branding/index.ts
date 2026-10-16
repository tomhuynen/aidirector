import DestroyController from './DestroyController'
import StoreController from './StoreController'

const Branding = {
  StoreController: Object.assign(StoreController, StoreController),
  DestroyController: Object.assign(DestroyController, DestroyController),
}

export default Branding
