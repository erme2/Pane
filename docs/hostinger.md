# Hostinger Runtime Preparation

Pane's first alpha release targets Hostinger as a production-like Laravel host.
This runbook documents the required runtime assumptions and the local preflight
checks that should pass before deployment.

## Required Runtime

- PHP 8.5 or newer.
- Composer available over SSH.
- PHP extensions required by Laravel and Pane:
  - `ctype`
  - `curl`
  - `dom`
  - `fileinfo`
  - `filter`
  - `hash`
  - `mbstring`
  - `openssl`
  - `pcre`
  - `PDO`
  - `pdo_mysql`
  - `session`
  - `tokenizer`
  - `xml`
- MySQL or MariaDB reachable from the PHP runtime.
- The web server document root must point at Laravel's `public/` directory.
- `storage/` and `bootstrap/cache/` must exist and be writable by the PHP user.
- A production `.env` file must exist on the host and must not be committed.

## Production Environment

Production must use these safety settings:

```env
APP_ENV=production
APP_DEBUG=false
SESSION_SECURE_COOKIE=true
APP_URL=https://pane.erme2.com
FRONTEND_URL=https://latte.erme2.com
```

Generate a unique Laravel key on the host:

```bash
php artisan key:generate --force
```

Generate managed-credential key material separately from `APP_KEY`, store it in
the production environment, and keep older key IDs available during rotation.
Do not print or paste `APP_KEY`, WorkOS secrets, database credentials, or
managed-credential keys in tickets, logs, release notes, or support messages.

## Preflight Script

Run the preflight from the repository root after the production `.env` file is
present:

```bash
./bash/hostinger-preflight.sh
```

To check a staged environment file before copying it to `.env`:

```bash
./bash/hostinger-preflight.sh -e .env.production
```

To skip the live database connection check while preparing a host that does not
yet have database credentials:

```bash
./bash/hostinger-preflight.sh -e .env.production -d no
```

The script verifies:

- PHP and Composer are available.
- PHP version and required extensions are compatible.
- Production environment keys are present without printing their values.
- The selected `-e` environment file is also used for the Laravel boot/cache
  check through a temporary `.env.*` file that is removed before exit.
- Placeholder production secrets are rejected.
- `APP_ENV`, `APP_DEBUG`, HTTPS URLs, and secure session cookies are safe for
  production.
- Managed-credential key configuration is syntactically usable.
- Laravel writable directories are ready.
- MySQL/MariaDB connectivity works unless `-d no` is used.
- Composer production platform requirements pass.

The script reports only key names and pass/fail status. It must not print
secret values.
