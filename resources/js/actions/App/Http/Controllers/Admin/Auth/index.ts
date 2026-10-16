import AuthenticateUsingPasskeyController from './AuthenticateUsingPasskeyController'
import InviteController from './InviteController'

const Auth = {
  InviteController: Object.assign(InviteController, InviteController),
  AuthenticateUsingPasskeyController: Object.assign(
    AuthenticateUsingPasskeyController,
    AuthenticateUsingPasskeyController,
  ),
}

export default Auth
