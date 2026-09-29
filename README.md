# Blueprint

Starting point for new Vagebond projects: Laravel 12, Inertia v3 + Vue 3, multi-tenant (landlord + tenant databases), with separate public and admin frontends.

## Starting a new project from this blueprint

Clone the repository and run `app:core-init`. It keeps the blueprint as a read-only remote so you can pull future blueprint improvements into your project with `git pull blueprint main`.

### 1. Clone into a new Herd site and initialise

Herd serves any folder under `~/Sites/vagebond` as `https://<folder>.test`, so the folder name becomes the local URL.

```bash
cd ~/Sites/vagebond
git clone git@github.com:vagebnd/blueprint.git myproject
cd myproject
composer install
php artisan app:core-init
```

`app:core-init` does the following:

1. Disables pushing to `origin` by setting its push URL to `no-pushing`.
2. Renames `origin` to `blueprint`.
3. Prompts for the new project's repository URL and adds it as the new `origin` (leave blank to skip).

Pass `--force` to skip the confirmation. Afterwards create the repository on GitHub and publish:

```bash
git push -u origin main
```

### 2. Configure the environment

```bash
cp .env.example .env
```

Edit at least:

- `APP_NAME` and `APP_TITLE`
- `APP_URL` (for example `https://myproject.test`)
- `DB_DATABASE` — this is the **landlord** database. All tenant databases are derived from it.

The cache and session stores are SQLite files (`database/cache.sqlite`, `database/sessions.sqlite`) that are created automatically by the migrate command.

### 3. Install dependencies

The project pins pnpm (see `packageManager` in `package.json`) and Node 22 (`.nvmrc`).

```bash
php artisan key:generate
nvm use && pnpm install
```

### 4. Create the landlord database and migrate

MySQL must contain an empty database matching `DB_DATABASE`.

```bash
mysql -uroot -proot -e "CREATE DATABASE myproject"
php artisan app:migrate --fresh --seed
```

`app:migrate` runs the cache, sessions, landlord and tenant migrations in one go. Use `--fresh` to drop and recreate everything (including tenant databases) and `--seed` to seed. Seeding creates a landlord user and, per tenant, activity records.

### 5. Create the first tenant and user

```bash
php artisan make:tenant
php artisan make:user
```

`make:tenant` prompts for a name and domain (defaulting to `<slug>.<APP_URL host>`) and creates and migrates the tenant database. `make:user` picks a tenant, creates a user with roles through Fortify and prints the generated password.

### 6. Build the frontends

There are two frontends, each with its own Vite config: the public site and the admin.

```bash
pnpm public:build && pnpm admin:build
```

During development run the watchers instead:

```bash
pnpm public:watch
pnpm admin:watch
```

### 7. Queue

Queues run on Redis via Horizon.

```bash
herd services:start redis
php artisan horizon
```

### 8. Clean up blueprint references

After cloning, review project-specific files that still refer to the blueprint, such as `.claude/`, `.mcp.json` and `boost.json`, and rename or remove what does not apply.
