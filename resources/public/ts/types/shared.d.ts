export type Account = {
  name: string
  email: string
  links: {
    projects: string
    logout: string
    notifications: string
    notificationsRead: string
  }
}

export type PageProps = {
  app: {
    env: string
    title: string
    route: string
  }
  account: Account | null
}
