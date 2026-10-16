import CreateController from './CreateController'
import DeleteController from './DeleteController'
import OptionsController from './OptionsController'

const Passkeys = {
  OptionsController: Object.assign(OptionsController, OptionsController),
  CreateController: Object.assign(CreateController, CreateController),
  DeleteController: Object.assign(DeleteController, DeleteController),
}

export default Passkeys
