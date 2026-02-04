export type NavigationItem = {
  active: boolean
  href: string
  target: string
  hasIcon: boolean
  icon?: string
  title: string
  items?: NavItem[]
  external?: boolean
}

export type NavigationGroup = {
  title: string
  items: NavigationItem[]
}

export type Account = {
  name: string
  email: string
  avatar: string
  links: NavigationItem[]
}

export type PageProps = {
  isImpersonated: boolean
  app: {
    env: string
    title: string
    route: string
    account: Account
    navigation: NavigationGroup[]
  }
  page: {
    breadcrumbs: BreadcrumbItem[]
    actions: PageAction[]
  }
}

export type BreadcrumbItem = {
  title: string
  href?: string
}

export type PageAction = {
  title: string
  action: string
  icon?: string
  disabled?: boolean
}
