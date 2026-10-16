import IndexController from './IndexController'
import ViewController from './ViewController'

const Search = {
  IndexController: Object.assign(IndexController, IndexController),
  ViewController: Object.assign(ViewController, ViewController),
}

export default Search
