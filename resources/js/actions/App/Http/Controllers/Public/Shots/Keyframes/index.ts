import CopyController from './CopyController'
import DestroyController from './DestroyController'
import FirstController from './FirstController'
import GenerateController from './GenerateController'
import ImageController from './ImageController'
import LayoutController from './LayoutController'
import MoveController from './MoveController'
import RenderController from './RenderController'
import ReorderController from './ReorderController'
import StoreController from './StoreController'
import TweakController from './TweakController'
import UpdateController from './UpdateController'

const Keyframes = {
  StoreController: Object.assign(StoreController, StoreController),
  ReorderController: Object.assign(ReorderController, ReorderController),
  DestroyController: Object.assign(DestroyController, DestroyController),
  CopyController: Object.assign(CopyController, CopyController),
  GenerateController: Object.assign(GenerateController, GenerateController),
  FirstController: Object.assign(FirstController, FirstController),
  UpdateController: Object.assign(UpdateController, UpdateController),
  TweakController: Object.assign(TweakController, TweakController),
  LayoutController: Object.assign(LayoutController, LayoutController),
  MoveController: Object.assign(MoveController, MoveController),
  RenderController: Object.assign(RenderController, RenderController),
  ImageController: Object.assign(ImageController, ImageController),
}

export default Keyframes
