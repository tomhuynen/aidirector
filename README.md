# Blueprint

Starting point for new Vagebond projects: Laravel 12, Inertia v3 + Vue 3, multi-tenant (landlord + tenant databases), with separate public and admin frontends.

## Starting a new project from this blueprint

This repository is not a GitHub template, so start from a fresh clone with a new git history.

### 1. Clone into a new Herd site

Herd serves any folder under `~/Sites/vagebond` as `https://<folder>.test`, so the folder name becomes the local URL.

```bash
cd ~/Sites/vagebond
git clone --depth 1 git@github.com:vagebnd/blueprint.git myproject
cd myproject
rm -rf .git && git init && git add -A && git commit -m "chore: bootstrap from blueprint"
git remote add origin git@github.com:vagebnd/myproject.git   # after creating the repo on GitHub
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
composer install
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

### 5. Build the frontends

There are two frontends, each with its own Vite config: the public site and the admin.

```bash
pnpm public:build && pnpm admin:build
```

During development run the watchers instead:

```bash
pnpm public:watch
pnpm admin:watch
```

### 6. Queue

Queues run on Redis via Horizon.

```bash
herd services:start redis
php artisan horizon
```

### 7. Clean up blueprint references

After cloning, review project-specific files that still refer to the blueprint, such as `.claude/`, `.mcp.json` and `boost.json`, and rename or remove what does not apply.
