export { default as IpAddressCell } from './IpAddressCell.vue'
export { default as RelativeTimeCell } from './RelativeTimeCell.vue'
export { default as UserAgentCell } from './UserAgentCell.vue'

// Cell value types
export interface IpAddressValue {
  value: string
  bogon?: boolean
  countryCode?: string
  countryFlag?: string
  organization?: string
}

export interface UserAgentValue {
  value: string
  deviceTypeIcon: string
  isBot?: boolean
  clientFamily?: string
  clientVersion?: string
  osName?: string
  osVersion?: string
}
