# Environment Configuration

Pane uses `.env.example` as the template for local and deployed environment files. Copy it to `.env` for local work, then set values that match the environment you are running.

## Application

- `APP_NAME` names the application in framework output.
- `APP_ENV` controls environment-sensitive behavior. Use `local` or `testing` for local/test runs. Use `production`, `staging`, or another non-local value for deployed environments.
- `APP_KEY` is the Laravel encryption key. Keep it blank in committed templates and generate a real value for every local, CI, staging, and production environment.
- `APP_DEBUG` must be `false` in production. Pane fails closed when production debug is enabled.
- `PANE_MANAGED_CREDENTIAL_ACTIVE_KEY_ID` selects the external managed-credential key used for new managed-database secret writes.
- `PANE_MANAGED_CREDENTIAL_KEYS` is a JSON object mapping key IDs to `base64:` encoded 32-byte keys. Keep this material outside Pane's primary database and separate from `APP_KEY`; retain old key IDs during rotation so existing envelopes can still decrypt.

Example managed-credential key configuration:

```env
PANE_MANAGED_CREDENTIAL_ACTIVE_KEY_ID=2026-08-primary
PANE_MANAGED_CREDENTIAL_KEYS={"2026-07-primary":"base64:old-32-byte-key-material","2026-08-primary":"base64:new-32-byte-key-material"}
```

## URLs And Trusted Hosts

- `APP_URL` is Pane's canonical origin. It is used for URL generation and is always included in the trusted-host list outside local/test environments.
- `TRUSTED_HOSTS` is a comma-separated list of extra hostnames that legitimately route to Pane and are not already covered by `APP_URL`.
- `pane.localhost` is already covered when `APP_URL=https://pane.localhost`, so it does not need to be repeated in `TRUSTED_HOSTS`.
- `latte.localhost` should not normally be in `TRUSTED_HOSTS`. Latte proxies `/pane/*` requests to Pane and should send Pane a Pane host header such as `pane.localhost` through Latte's `VITE_PANE_PROXY_HOST=pane.localhost` setting.
- Add `latte.localhost` only if Pane actually receives requests with `Host: latte.localhost`; that would mean the proxy is preserving Latte's browser host instead of sending Pane's host.
- Pane does not run frontend Nginx vhosts for Latte-derived apps. Latte-derived frontends own their browser hostnames, HTTPS certificates, and `/pane` proxy.

Examples:

```env
APP_URL=https://pane.localhost
TRUSTED_HOSTS=
```

```env
APP_URL=https://pane.example.com
TRUSTED_HOSTS=pane.staging.example.com,pane.internal.example.com
```

After copying any committed environment template to a real environment file, generate an environment-specific key:

```bash
php artisan key:generate --force
```

## Session And Cookies

- `SESSION_COOKIE` names Pane's Laravel session cookie.
- `SESSION_SECURE_COOKIE` should be `true` for HTTPS environments. Pane requires secure session cookies outside `local` and `testing`.

## Logging

- `LOG_CHANNEL`, `LOG_DEPRECATIONS_CHANNEL`, and `LOG_LEVEL` configure Laravel logging. Local development usually keeps `LOG_LEVEL=debug`; deployed environments should use a level appropriate to the operational setup.

## Database

- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` configure Pane's database connection.
- `DB_TABLE_PREFIX` prefixes Pane-owned system and control-plane tables. Legacy mapper metadata tables keep the extra `map_` segment after this prefix. The Docker/local default is `pane_`.

## Cache And Filesystem

- `CACHE_DRIVER` and `FILESYSTEM_DISK` configure framework services used by Pane. The example values are local-development defaults.

## WorkOS And Latte

- `WORKOS_API_KEY`, `WORKOS_CLIENT_ID`, `WORKOS_PROVIDER`, `WORKOS_ORGANIZATION_ID`, and `WORKOS_CONNECTION_ID` configure WorkOS AuthKit for Pane.
- `FRONTEND_URL` is the trusted Latte origin Pane uses for v1 session application projection and CORS.
- `LATTE_REDIRECT_URIS` is a comma-separated list of exact, normalized Latte return URLs that v1 login intents may use.
- `WORKOS_REDIRECT_URI` should point to Latte's callback route in the Latte/Pane browser flow, for example `https://latte.localhost/auth/callback`.
- `WORKOS_RETURN_TO` optionally overrides the WorkOS logout return URL. When it is unset, Pane sends users back to the normalized `FRONTEND_URL` root, for example `https://latte.localhost/`.

For the full WorkOS and Latte browser flow, see [WorkOS and Latte Authentication](workos-latte-auth.md).

## Release Metadata

Release metadata is not part of the application `.env` contract. Pane exposes
non-secret build metadata through `GET /api/v1/release`. Local checkouts derive
version/ref/commit from Git when possible. GitHub Actions deploys should cache
release metadata from workflow context with:

```bash
php artisan release:cache --release-version="$GITHUB_REF_NAME" \
  --ref="$GITHUB_REF_NAME" \
  --commit="$GITHUB_SHA" \
  --built-at="<timestamp>"
```
