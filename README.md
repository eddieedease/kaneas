# Kaneas

Collaborative kanban boards. Users sign in, create boards and invite other people to work on them.

- **Frontend:** Angular 22 (zoneless, standalone components, signals, Signal Forms), Tailwind CSS 4, ngx-translate (Dutch default, English)
- **Backend:** plain PHP 8.2+ with PDO/MySQL — no Composer dependencies, so it runs on shared hosting
- **Auth:** short-lived JWT access tokens (in memory) + rotating httpOnly refresh-token cookie
- **Deploy:** one build command produces a single folder with frontend, API and a web installer; works in a sub folder

## Requirements

- Node.js 24 (or ≥ 22.22.3) — see `.nvmrc`
- Docker Desktop

> **Windows + nvm-windows:** `nvm use 24` needs an elevated (administrator) terminal. Node 24 is installed; run `nvm use 24` once as administrator to make it the default.

## Development

```bash
npm run docker:up                  # PHP/Apache :8080, MySQL :3307, phpMyAdmin :8081
npm --prefix frontend install
npm start                          # Angular dev server on http://localhost:4200
```

The PHP container installs Kaneas automatically when the database is empty (first start, or after `docker compose down -v`): tables, `backend/api/config/config.php` and these dev accounts (set in `docker-compose.yml`):

| Role  | Email               | Password   |
|-------|---------------------|------------|
| admin | `admin@example.com` | `password` |
| user  | `user@example.com`  | `password` |

| Service    | URL                            |
|------------|--------------------------------|
| App        | http://localhost:4200          |
| API        | http://localhost:4200/api (proxied to :8080) |
| phpMyAdmin | http://localhost:8081 (`root` / `root`) |
| MySQL      | `localhost:3307` (`kaneas` / `kaneas`) |

The Angular dev server proxies `/api` to Docker (`frontend/proxy.conf.json`), so the app and API share one origin — exactly like production. No CORS is needed or enabled.

**Reset the dev database:** `docker compose down -v && npm run docker:up` — it reinstalls itself with the dev accounts.

The auto install (`backend/install/cli.php`) is development only; production installs always go through the web installer.

## Build & deploy to shared hosting

```bash
npm run build        # -> dist/kaneas/
npm run build:zip    # -> dist/kaneas/ + dist/kaneas-<version>.zip
```

`dist/kaneas/` contains everything:

```
index.html, *.js, *.css, i18n/   Angular production build
.htaccess                        SPA routing + caching
api/                             PHP API (src/ and config/ are blocked from the web)
install/                         web installer
```

1. Create a MySQL database (and user) in your hosting panel.
2. Upload the **contents** of `dist/kaneas/` to any folder, e.g. `public_html/kanban/`.
3. Open `https://your-domain/kanban/install/` and fill in the database, the admin account and (optionally) SMTP mail settings.

The installer detects the folder it runs in and patches `<base href>` and `RewriteBase`, writes `api/config/config.php` (JWT secret and encryption key are generated) and then locks itself. Delete the `install/` folder afterwards to be safe.

Test a release locally, served from a sub folder like on shared hosting:

```bash
npm run build
docker compose --profile release up -d    # http://localhost:8090/kanban/install/
```

Requirements on the host: Apache with `mod_rewrite` and `.htaccess` (`AllowOverride All`), PHP ≥ 8.2 with `pdo_mysql`, `openssl`, `mbstring`, MySQL 5.7+/MariaDB 10.3+.

## Roles

| Scope  | Role     | Can |
|--------|----------|-----|
| System | `admin`  | manage users (role, block, delete), registration on/off, SMTP settings |
| System | `user`   | create boards, collaborate |
| Board  | `owner`  | the creator: everything, incl. adding/removing people and deleting the board |
| Board  | `editor` | manage columns and cards |
| Board  | `viewer` | read only |

Adding a person by email adds existing users immediately. For an unknown email a pending invitation is stored; it becomes a membership when that person registers. If SMTP is configured, an email is sent in both cases.

## Security notes

- Passwords: Argon2id (bcrypt fallback), rehash on login, constant-time checks against a dummy hash for unknown emails.
- Access token: HS256 JWT, 15 minutes, kept in memory only (never `localStorage`). Only `HS256` is accepted.
- Refresh token: 384-bit random, stored as SHA-256 hash, `httpOnly; SameSite=Strict` cookie scoped to `api/auth`, rotated on every use. Reusing a rotated token revokes the whole session family (30 s grace for parallel tabs).
- Refresh/logout require a JSON content type (forces a CORS preflight cross-site).
- The user is loaded from the database on every request, so blocking a user or changing a role takes effect immediately.
- Rate limiting (database backed): 5 failed logins per email+IP and 30 per IP per 15 min; 10 registrations per IP per hour.
- All SQL uses prepared statements; board ids are not probeable (non-members get 404).
- SMTP password is encrypted (AES-256-GCM) with the app key from `config.php`.

## API overview

All routes live under `api/`. Errors are `{ "error": "<code>", "details"?: {...} }`; the frontend translates `errors.<code>`.

| Method | Route | |
|---|---|---|
| POST | `auth/register`, `auth/login`, `auth/refresh`, `auth/logout` | session |
| GET/PATCH | `auth/me` · POST `auth/password` · GET `auth/config` | profile |
| GET/POST | `boards` · GET/PATCH/DELETE `boards/{id}` | boards |
| POST | `boards/{id}/columns` · PUT `boards/{id}/columns/order` · PATCH/DELETE `columns/{id}` | columns |
| POST | `columns/{id}/cards` · PATCH/DELETE `cards/{id}` · POST `cards/{id}/move` | cards |
| GET/POST | `boards/{id}/members` · PATCH/DELETE `boards/{id}/members/{userId}` · DELETE `boards/{id}/invitations/{id}` | collaboration |
| GET/PATCH/DELETE | `admin/users[/{id}]` · GET/PATCH `admin/settings` · GET/PUT `admin/settings/mail` · POST `admin/settings/mail/test` | admin |

## Project layout

```
backend/api/          PHP API (front controller index.php, src/Core, src/Services, src/Controllers)
backend/install/      web installer + schema.sql
frontend/             Angular app (src/app/core, features, layout, shared; public/i18n)
deploy/htaccess       root .htaccess for the deployable build
scripts/build.mjs     build script for the deployable folder
docker/               dev PHP image (Apache + mod_rewrite, like shared hosting)
```
