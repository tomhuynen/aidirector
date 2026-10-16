import Accounts from './Accounts'
import Activities from './Activities'
import Auth from './Auth'
import Config from './Config'
import Dashboard from './Dashboard'
import Settings from './Settings'
import System from './System'
import Tenants from './Tenants'
import Widgets from './Widgets'

const Admin = {
  Dashboard: Object.assign(Dashboard, Dashboard),
  Accounts: Object.assign(Accounts, Accounts),
  Activities: Object.assign(Activities, Activities),
  Config: Object.assign(Config, Config),
  Settings: Object.assign(Settings, Settings),
  Tenants: Object.assign(Tenants, Tenants),
  System: Object.assign(System, System),
  Widgets: Object.assign(Widgets, Widgets),
  Auth: Object.assign(Auth, Auth),
}

export default Admin
