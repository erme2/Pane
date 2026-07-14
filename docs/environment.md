# Environment Configuration

Pane uses `.env.example` as the template for local and deployed environment files. Copy it to `.env` for local work, then set values that match the environment you are running.

## Application

- `APP_NAME` names the application in framework output.
- `APP_ENV` controls environment-sensitive behavior. Use `local` or `testing` for local/test runs. Use `production`, `staging`, or another non-local value for deployed environments.
- `APP_KEY` is the Laravel encryption key. Generate a real value for every environment.
- `APP_DEBUG` must be `false` in production. Pane fails closed when production debug is enabled.

## URLs And Trusted Hosts

- `APP_URL` is Pane's canonical origin. It is used for URL generation and is always included in the trusted-host list outside local/test environments.
- `TRUSTED_HOSTS` is a comma-separated list of extra hostnames that legitimately route to Pane and are not already covered by `APP_URL`.
- `pane.localhost` is already covered when `APP_URL=https://pane.localhost`, so it does not need to be repeated in `TRUSTED_HOSTS`.
- `burro.localhost` should not normally be in `TRUSTED_HOSTS`. Burro proxies `/pane/*` requests to Pane and should send Pane a Pane host header such as `pane.localhost` through Burro's `VITE_PANE_PROXY_HOST=pane.localhost` setting.
- Add `burro.localhost` only if Pane actually receives requests with `Host: burro.localhost`; that would mean the proxy is preserving Burro's browser host instead of sending Pane's host.

Examples:

```env
APP_URL=https://pane.localhost
TRUSTED_HOSTS=
```

```env
APP_URL=https://pane.example.com
TRUSTED_HOSTS=pane.staging.example.com,pane.internal.example.com
```

## Session And Cookies

- `SESSION_COOKIE` names Pane's Laravel session cookie.
- `SESSION_SECURE_COOKIE` should be `true` for HTTPS environments. Pane requires secure session cookies outside `local` and `testing`.

## Logging

- `LOG_CHANNEL`, `LOG_DEPRECATIONS_CHANNEL`, and `LOG_LEVEL` configure Laravel logging. Local development usually keeps `LOG_LEVEL=debug`; deployed environments should use a level appropriate to the operational setup.

## Database

- `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` configure Pane's database connection.
- `DB_TABLE_PREFIX` prefixes Pane's system mapping tables. The Docker/local default is `pane_`.

## Cache, Broadcast, And Filesystem

- `BROADCAST_DRIVER`, `CACHE_DRIVER`, and `FILESYSTEM_DISK` configure framework services used by Pane. The example values are local-development defaults.

## WorkOS And Burro

- `WORKOS_API_KEY`, `WORKOS_CLIENT_ID`, `WORKOS_PROVIDER`, `WORKOS_ORGANIZATION_ID`, and `WORKOS_CONNECTION_ID` configure WorkOS AuthKit for Pane.
- `FRONTEND_URL` is the trusted Burro origin Pane may return users to after authentication.
- `WORKOS_REDIRECT_URI` should point to Burro's callback route in the Burro/Pane browser flow, for example `https://burro.localhost/auth/callback`.
- `WORKOS_RETURN_TO` is the post-login return origin or URL used by WorkOS/Pane.

For the full WorkOS and Burro browser flow, see [WorkOS and Burro Authentication](workos-burro-auth.md).
