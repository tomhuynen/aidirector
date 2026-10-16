import DatabaseController from './DatabaseController'
import ServerController from './ServerController'

const System = {
  ServerController: Object.assign(ServerController, ServerController),
  DatabaseController: Object.assign(DatabaseController, DatabaseController),
}

export default System
