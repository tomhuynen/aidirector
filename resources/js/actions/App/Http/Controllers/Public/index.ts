import Auth from './Auth'
import Media from './Media'
import Projects from './Projects'
import Shots from './Shots'

const Public = {
  Auth: Object.assign(Auth, Auth),
  Projects: Object.assign(Projects, Projects),
  Media: Object.assign(Media, Media),
  Shots: Object.assign(Shots, Shots),
}

export default Public
