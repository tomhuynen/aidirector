import Passkeys from './Passkeys'
import PasswordController from './PasswordController'
import ProfileController from './ProfileController'
import SecurityController from './SecurityController'

const Settings = {
  ProfileController: Object.assign(ProfileController, ProfileController),
  PasswordController: Object.assign(PasswordController, PasswordController),
  SecurityController: Object.assign(SecurityController, SecurityController),
  Passkeys: Object.assign(Passkeys, Passkeys),
}

export default Settings
