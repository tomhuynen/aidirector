import ForgotPasswordController from './ForgotPasswordController'
import LoginController from './LoginController'
import RegisterController from './RegisterController'
import ResetPasswordController from './ResetPasswordController'

const Auth = {
  LoginController: Object.assign(LoginController, LoginController),
  RegisterController: Object.assign(RegisterController, RegisterController),
  ForgotPasswordController: Object.assign(ForgotPasswordController, ForgotPasswordController),
  ResetPasswordController: Object.assign(ResetPasswordController, ResetPasswordController),
}

export default Auth
