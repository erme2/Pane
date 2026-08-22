# Pane

Pane is an independently installable Laravel data-access control plane. It owns
authentication, organization isolation, authorization, encrypted database
connections, catalog discovery, descriptions, and controlled CRUD for
applications created from Latte.

Each Pane installation is an independent universe that may serve many unrelated
organizations. Every Latte-derived application is linked to exactly one
organization and uses Pane as its only data-access layer. A separate Burro
application is planned as the private Pane-administrator console.

The current code is a transitional metadata-driven CRUD implementation over one
default database. The target introduces invite-only multi-tenancy, managed
MySQL/MariaDB connections, system-catalog discovery, membership-owned rows,
connection grants, impersonation, and auditing. Read
[Pane Architecture](docs/architecture.md) before making structural changes.

Pane remains focused on three qualities:

1. simple and lightweight;
2. testable;
3. easy to use.

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

Pane validates Host headers outside local/test environments. Set `APP_URL` to
the canonical Pane origin and put only extra Pane hostnames in
`TRUSTED_HOSTS`. See [Environment Configuration](docs/environment.md) for the
complete `.env.example` contract.

## Local development requirements

- Docker Desktop with Docker Compose

Pane's Compose stack runs the backend application and database on the shared
local Docker network. It does not run browser-facing Nginx vhosts for
Latte-derived applications.

Latte-derived frontends own their browser hostnames, HTTPS certificates, and
`/pane` proxy. For local browser access, start the target Latte-derived app and
use that app's documented hostname, for example `https://latte.localhost`.

## Testing

Pane keeps isolated unit tests under `tests/Unit` and database-backed integration or request tests under `tests/Feature`. See [Testing](docs/testing.md) for the suite contract, testing environment, and database refresh workflow.

## Phase-one API contract

The exact versioned routes, verification order, envelopes, statuses, error
codes, and migration policy are defined in the [Phase-One HTTP API](docs/api-v1.md).
Frontend consumers can validate requests and responses or generate clients from
the machine-readable OpenAPI 3.1
[`contracts/pane-v1.json`](contracts/pane-v1.json) contract.

## Static analysis

Pane uses Larastan, PHPStan's Laravel extension, to complement the test suite. Tests verify expected behavior; static analysis catches incorrect type assumptions and code paths the tests may not cover.

Run it locally with:

```bash
composer analyse
```

The initial level is 5. `phpstan-baseline.neon` records the technical debt that already existed when Larastan was introduced; new errors are not covered by the baseline and fail both `composer analyse` and the PR workflow.

## Bash scripts

Pane keeps repository helper scripts under `bash/` for cache clearing, local certificate generation, database refreshes, and test runs. See [Bash Scripts](docs/bash.md) for command usage, options, destructive operations, and common workflows.

## WorkOS Auth

Set these values in your environment:

```dotenv
WORKOS_API_KEY=sk_test_...
WORKOS_CLIENT_ID=client_...
FRONTEND_URL=https://latte.localhost
LATTE_REDIRECT_URIS=https://latte.localhost/auth/callback,https://latte.localhost/dashboard
WORKOS_REDIRECT_URI=https://latte.localhost/auth/callback
WORKOS_RETURN_TO=
WORKOS_PROVIDER=authkit
SESSION_COOKIE=pane_session
SESSION_SECURE_COOKIE=false
```

Add `WORKOS_REDIRECT_URI` to the Redirects tab for your WorkOS application.
Also add `https://latte.localhost/` as a Sign-out redirect. When
`WORKOS_RETURN_TO` is blank, Pane sends WorkOS logout back to the normalized
`FRONTEND_URL` root.

Create the first Latte organization administrator invite with:

```bash
php artisan latte:bootstrap-organization first.admin@example.com
```

Omit the email argument to be prompted interactively.
The command temporarily uses that same email as the bootstrap actor and removes
the temporary actor after creating the invitation.

Routes:

1. `GET /auth/login-url` returns the WorkOS AuthKit authorization URL as JSON.
2. `POST /auth/callback` exchanges WorkOS callback params, creates the Pane session, and returns the authenticated user to Latte.
3. `GET /auth/login` redirects to WorkOS AuthKit.
4. `GET /auth/callback` handles the WorkOS callback for legacy Pane-owned redirects.
5. `GET /auth/user` returns the user attached to the authenticated Pane session.

Pane does not store WorkOS access or refresh tokens in the Laravel session after login. Latte receives only the user snapshot and organization ID.

For the full Latte and Pane callback sequence, see [WorkOS and Latte Authentication](docs/workos-latte-auth.md).

For how that authenticated session gates CRUD routes, see [CRUD Authentication and Authorization](docs/crud-authentication.md).

## License

Pane is licensed under GPL-3.0-only.
