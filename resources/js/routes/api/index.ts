import auth from './auth'
import v1 from './v1'

const api = {
  auth: Object.assign(auth, auth),
  v1: Object.assign(v1, v1),
}

export default api
