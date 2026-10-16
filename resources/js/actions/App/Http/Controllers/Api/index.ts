import Auth from './Auth'
import V1 from './V1'

const Api = {
  Auth: Object.assign(Auth, Auth),
  V1: Object.assign(V1, V1),
}

export default Api
