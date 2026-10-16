import MeController from './MeController'
import TokenController from './TokenController'

const Auth = {
  TokenController: Object.assign(TokenController, TokenController),
  MeController: Object.assign(MeController, MeController),
}

export default Auth
