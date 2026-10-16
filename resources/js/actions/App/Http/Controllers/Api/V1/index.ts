import ProjectController from './ProjectController'
import ShotController from './ShotController'

const V1 = {
  ProjectController: Object.assign(ProjectController, ProjectController),
  ShotController: Object.assign(ShotController, ShotController),
}

export default V1
