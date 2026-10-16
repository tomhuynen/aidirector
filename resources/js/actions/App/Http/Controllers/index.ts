import Admin from './Admin'
import Api from './Api'
import HomeController from './HomeController'
import Public from './Public'
import Uploads from './Uploads'

const Controllers = {
  Api: Object.assign(Api, Api),
  HomeController: Object.assign(HomeController, HomeController),
  Public: Object.assign(Public, Public),
  Uploads: Object.assign(Uploads, Uploads),
  Admin: Object.assign(Admin, Admin),
}

export default Controllers
