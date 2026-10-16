import CreateController from './CreateController'
import DestroyController from './DestroyController'
import DurationController from './DurationController'
import IssueController from './IssueController'
import Keyframes from './Keyframes'
import MergeController from './MergeController'
import Plan from './Plan'
import ReorderController from './ReorderController'
import RetryController from './RetryController'
import Video from './Video'
import ViewController from './ViewController'
import VoiceOverController from './VoiceOverController'

const Shots = {
  CreateController: Object.assign(CreateController, CreateController),
  ReorderController: Object.assign(ReorderController, ReorderController),
  DestroyController: Object.assign(DestroyController, DestroyController),
  MergeController: Object.assign(MergeController, MergeController),
  ViewController: Object.assign(ViewController, ViewController),
  Plan: Object.assign(Plan, Plan),
  Keyframes: Object.assign(Keyframes, Keyframes),
  IssueController: Object.assign(IssueController, IssueController),
  RetryController: Object.assign(RetryController, RetryController),
  Video: Object.assign(Video, Video),
  DurationController: Object.assign(DurationController, DurationController),
  VoiceOverController: Object.assign(VoiceOverController, VoiceOverController),
}

export default Shots
