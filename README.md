# Pane

Pane is a simple, lightweight, and easy Restful API framework based on [Laravel](https://laravel.com/).

Pane (Bread) is designed to be used with [Burro](https://github.com/erme2/Burro) (Butter).

Pane and Burro together should be like a "bread and butter".

1. keep it simple and lightweight
2. testable
3. easy to use

## Deployment requirements

Production deployments must run with `APP_ENV=production` and `APP_DEBUG=false`.
Pane fails during application boot when `APP_ENV=production` and `APP_DEBUG=true`
so production errors cannot expose source paths, stack traces, or other internal
exception details.

Session cookies are secure by default when `APP_ENV` is not `local` or
`testing`. Production, staging, and any other non-local deployment must run over
HTTPS and keep `SESSION_SECURE_COOKIE=true`; Pane fails during application boot
if a non-local environment disables secure session cookies.

Local HTTP development should use `APP_ENV=local` or `APP_ENV=testing`, where
secure session cookies are not forced. Local HTTPS Docker development can set
`SESSION_SECURE_COOKIE=true`.

## Local development requirements

- Docker Desktop with Docker Compose
- `mkcert` for locally trusted HTTPS certificates
- `nss` when using Firefox

On macOS, install the certificate tooling with Homebrew:

```bash
brew install mkcert
brew install nss # Required for Firefox trust-store support
mkcert -install
```

Add the local development domains to `/etc/hosts`:

```text
127.0.0.1 pane.localhost burro.localhost
```

Generate or regenerate the certificate covering both applications:

```bash
./bash/generate-certs.sh
```

The script creates `nginx/certs/localhost.pem` and `nginx/certs/localhost-key.pem`. The entire `nginx/certs` directory is ignored by Git because its private key is local development material. After Nginx HTTPS is configured, Pane and Burro are available at `https://pane.localhost` and `https://burro.localhost`.

## Bash scripts

Run scripts from the Pane repository root.

### `bash/clear.sh`

Clears Laravel's compiled files and application, configuration, event, route, schedule, and view caches. It uses the `testing` environment by default.

```bash
./bash/clear.sh [-f environment]
```

- `-f`: Laravel environment name; defaults to `testing`.

### `bash/generate-certs.sh`

Installs the local `mkcert` certificate authority when necessary and generates or replaces the shared Pane and Burro development certificate.

```bash
./bash/generate-certs.sh
```

The generated certificate and private key are written under the ignored `nginx/certs` directory.

### `bash/refresh.sh`

Rebuilds the configured database and runs migrations. By default it loads `.env.testing`, asks for confirmation, deletes and recreates the database, and does not seed or run test-only migrations. **This script is destructive when database deletion is enabled.**

```bash
./bash/refresh.sh [-c yes|no] [-d yes|no] [-f environment] \
  [-o yes|no] [-s yes|no] [-t yes|no] [-v yes|no]
```

- `-c`: clear Laravel caches; defaults to `no`.
- `-d`: delete and recreate the database; defaults to `yes`.
- `-f`: environment name; defaults to `testing`.
- `-o`: show the confirmation prompt; defaults to `yes`.
- `-s`: run `TestTableSeeder`; defaults to `no`.
- `-t`: run migrations under `database/migrations/test`; defaults to `no`.
- `-v`: print progress and selected options; defaults to `yes`.

### `bash/test.sh`

Optionally refreshes the test database, runs the Laravel test suite, and rolls back test-only migrations after a successful run. It uses the `testing` environment and asks for confirmation by default.

```bash
./bash/test.sh [-c environment] [-f yes|no] [-o yes|no] \
  [-r yes|no] [-s yes|no] [-u yes|no] [-v yes|no]
```

- `-c`: environment name; defaults to `testing`.
- `-f`: stop on the first test failure; defaults to `yes`.
- `-o`: show confirmation prompts; defaults to `yes`.
- `-r`: refresh the database before testing; defaults to `yes`.
- `-s`: run the test seeder during refresh; defaults to `yes`.
- `-u`: roll back test-only migrations after success; defaults to `yes`.
- `-v`: print progress and selected options; defaults to `yes`.

## WorkOS Auth

Set these values in your environment:

```dotenv
WORKOS_API_KEY=sk_test_...
WORKOS_CLIENT_ID=client_...
WORKOS_REDIRECT_URI=http://localhost:5173/auth/callback
WORKOS_RETURN_TO=http://localhost:5173
WORKOS_PROVIDER=authkit
SESSION_COOKIE=pane_session
SESSION_SECURE_COOKIE=false
```

Add `WORKOS_REDIRECT_URI` to the Redirects tab for your WorkOS application.

Routes:

1. `GET /auth/login-url` returns the WorkOS AuthKit authorization URL as JSON.
2. `POST /auth/callback` exchanges WorkOS callback params, creates the Pane session, and returns the authenticated user to Burro.
3. `GET /auth/login` redirects to WorkOS AuthKit.
4. `GET /auth/callback` handles the WorkOS callback for legacy Pane-owned redirects.
5. `GET /auth/user` returns the user attached to the authenticated Pane session.

Pane keeps WorkOS tokens in its private Laravel session. Burro receives only the user snapshot and organization ID.

For the full Burro and Pane callback sequence, see [WorkOS and Burro Authentication](docs/workos-burro-auth.md).

For how that authenticated session gates CRUD routes, see [CRUD Authentication and Authorization](docs/crud-authentication.md).
