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
PANE_STATUS_PASSWORD=<strong random password>
```

Generate a unique Laravel key on the host:

```bash
php artisan key:generate --force
```

Generate managed-credential key material separately from `APP_KEY`, store it in
the production environment, and keep older key IDs available during rotation.
Do not print or paste `APP_KEY`, WorkOS secrets, database credentials, or
managed-credential keys in tickets, logs, release notes, or support messages.

## GitHub Actions Deployment

Pane deploys to Hostinger through the
`.github/workflows/deploy-hostinger.yml` workflow. Merges to `main` deploy
automatically to the protected `production` Environment. The workflow can also
be run manually from the GitHub Actions UI. The Environment must require human
approval before secrets are exposed to the job.

Create these protected GitHub Environment secrets:

| Secret | Purpose |
| --- | --- |
| `PANE_PRODUCTION_ENV` | Full production Laravel `.env` content. Keep a copy in the password manager and update this secret before release when production values change. |
| `PANE_HOSTINGER_HOST` | Hostinger SSH host. |
| `PANE_HOSTINGER_USER` | Hostinger SSH user. |
| `PANE_HOSTINGER_PORT` | Hostinger SSH port. |
| `PANE_HOSTINGER_SSH_KEY` | Private SSH key used only for deployment. |
| `PANE_HOSTINGER_DEPLOY_PATH` | Must be `/home/u253124519/domains/erme2.com/public_html/pane`. |

The workflow writes `PANE_PRODUCTION_ENV` to a temporary runner file, validates
it with `bash/hostinger-preflight.sh -e "$PANE_ENV_FILE" -d no`, uploads the
release to Hostinger with `rsync`, copies the temporary env file to `.env` on
the host with restrictive permissions, and removes the runner-side file before
the job exits. It must not upload `.env` as an artifact or print secret values.

The deploy path is fixed to:

```text
/home/u253124519/domains/erme2.com/public_html/pane
```

Until the Hostinger web server is changed to point directly at Laravel's
`public/` directory, the workflow preserves Laravel's normal `public/` layout
inside that directory and deploys the full application tree there.

The workflow runs automatically when changes are merged to `main`. Automatic
`main` deployments use the `production` GitHub Environment, run the live
database preflight, run production migrations, and expose
`0.1.0-alpha.<GITHUB_RUN_NUMBER>` from `/api/v1/release`.

The workflow can also be run manually with inputs for:

- the protected GitHub Environment;
- an optional release version. When it is omitted, `main` runs default to
  `0.1.0-alpha.<GITHUB_RUN_NUMBER>` and non-main manual runs default to the
  selected Git ref name without a leading `v`. Release metadata values may
  contain only letters, numbers, `.`, `_`, `/`, `@`, `+`, and `-`;
- whether the Hostinger preflight should run the live database connectivity
  check;
- whether production migrations should run after preflight passes.

After upload, the workflow runs the Hostinger preflight on the remote directory,
optionally runs migrations, caches Laravel config/routes/views, and smoke
checks `/`, `/api/v1/release`, `/api/v1/session`, and
`/api/v1/installation/applications`. View caching is skipped when the deployed
tree has no `resources/views` directory.

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
