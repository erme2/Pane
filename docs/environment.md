# Environment Configuration

Pane uses `.env.example` as the template for local and deployed environment files. Copy it to `.env` for local work, then set values that match the environment you are running.

## Application

- `APP_NAME` names the application in framework output.
- `APP_ENV` controls environment-sensitive behavior. Use `local` or `testing` for local/test runs. Use `production`, `staging`, or another non-local value for deployed environments.
- `APP_KEY` is the Laravel encryption key. Keep it blank in committed templates and generate a real value for every local, CI, staging, and production environment.
- `APP_DEBUG` must be `false` in production. Pane fails closed when production debug is enabled.

## Trusted Frontends

Pane trusts the frontends listed in its environment, and nothing else. There is no application table and no API to manage them: change the env to add or remove one.

- `FRONTEND_URL` is the main frontend origin. `LATTE_REDIRECT_URIS` lists the redirect URIs it may use after sign-in.
- `PANE_EXTRA_FRONTEND_ORIGINS` adds more frontends: entries separated by `;`, each `origin` or `origin|redirect_uri,redirect_uri`. Without redirect URIs a frontend gets `<origin>/auth/callback`.
- Every frontend gets a stable id derived from its origin and `LATTE_APPLICATION_ID`, so ids and sessions survive redeploys. A frontend removed from the env stops resolving. Removal alone does not revoke its sessions: they would work again if the origin were re-added. Change `PANE_SESSION_EPOCH` (any string) when re-adding a frontend, or to sign every frontend out; it is part of the session version.

## Outgoing Email

Pane sends invitation email directly through Laravel Mail and the `MAIL_*` settings, right after the invitation is saved.

Every install also has a message queue (table `messages`, prefixed like the other Pane tables) for outgoing messages. A Pane feature adds a row with `App\Services\MessageQueue::push($channel, $recipient, $subject, $body)`, and `php artisan messages:send` sends the due rows through the row's channel (`email` uses the `MAIL_*` settings). A failed send is retried after 1, 5 and 30 minutes, then marked `failed` with the last error (links and addresses removed). A row left `sending` by a crashed run goes back to `pending` after 10 minutes. The body is encrypted at rest. The command is scheduled every minute, so the one `schedule:run` cron job runs it; an install that already calls `messages:send` from cron keeps working, since overlapping runs never send a message twice. A new channel (SMS, mobile push) implements `App\Messages\MessageChannel` and is registered in `config/pane.php` under `messages.channels`: no table or worker change.

- Creating an administrator invitation through the v1 API sends one email with the invitation link. Revoking sends none. The API response, including `meta.invitation_url`, is the same either way.
- If the send fails, the invitation stays: the failure is logged (invitation id and exception class only, never the address or link) and the caller still gets the link to pass on.

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
- `DB_TABLE_PREFIX` prefixes Pane-owned system and control-plane tables. Legacy mapper metadata tables keep the extra `map_` segment after this prefix. The Docker/local default is `pane_`. Pane adds it itself, so no database connection has a prefix, and Laravel's own tables (`migrations`, ...) stay unprefixed. An install created with an empty `DB_TABLE_PREFIX` moves to a prefix by setting it and deploying: the `prefix_unprefixed_pane_tables` migration renames its tables (back up the database first).
- `APP_TABLE_PREFIX` is the prefix of this install's own app tables (for example `sweegly_`). Optional for now: empty means the install has no app tables yet. Pane refuses to boot when it overlaps `DB_TABLE_PREFIX` (either one starting with the other), or when it is set while `DB_TABLE_PREFIX` is empty, so an app table can never be taken for a protected system table.

## Cache And Filesystem

- `CACHE_DRIVER` and `FILESYSTEM_DISK` configure framework services used by Pane. The example values are local-development defaults.
- `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT` and `R2_PUBLIC_URL` configure the `r2` disk, Cloudflare R2 over the S3 API, where image app settings (for example a logo) are uploaded. One bucket and one key set per install, from the deploy secrets (wired in erme2/deploy). `R2_ENDPOINT` is `https://<account id>.r2.cloudflarestorage.com`; `R2_PUBLIC_URL` is the public base URL of the bucket (custom domain or `r2.dev`) that image URLs are built from. Optional: without them everything works except uploads, which fail with a server error. Pane accepts PNG, JPEG and WebP up to 2 MB (never SVG), stores each upload under `settings/<key>/<uuid>.<ext>`, and never deletes old objects.

## WorkOS And Latte

- `WORKOS_API_KEY`, `WORKOS_CLIENT_ID`, `WORKOS_PROVIDER`, `WORKOS_ORGANIZATION_ID`, and `WORKOS_CONNECTION_ID` configure WorkOS AuthKit for Pane.
- `FRONTEND_URL` is the trusted Latte origin Pane uses for v1 session application projection and CORS.
- `LATTE_REDIRECT_URIS` is a comma-separated list of exact, normalized Latte return URLs that v1 login intents may use.
- `WORKOS_REDIRECT_URI` should point to Latte's callback route in the Latte/Pane browser flow, for example `https://latte.localhost/auth/callback`.
- `WORKOS_RETURN_TO` optionally overrides the WorkOS logout return URL. When it is unset, Pane sends users back to the normalized `FRONTEND_URL` root, for example `https://latte.localhost/`.
- `PANE_SIGNUP_OPEN` (default `false`) turns open sign-up on: an unknown email that WorkOS verified becomes a standard user without an invitation. Administrators always need an invitation. Changing it is a deploy, not an API call.
- `PANE_EXTRA_FRONTEND_ORIGINS` lists extra frontends this installation trusts besides `FRONTEND_URL`, all in the same configured organization. Entries are separated by `;`, each `origin` or `origin|redirect_uri,redirect_uri`, for example `https://admin.dev.sweegly.co.uk|https://admin.dev.sweegly.co.uk/auth/callback,https://admin.dev.sweegly.co.uk/dashboard`. Without redirect URIs an entry gets `<origin>/auth/callback`. These frontends use the `WORKOS_REDIRECT_URI` path on their own origin and log out to their own root, so add both to WorkOS. Removing an entry disables that frontend; change `PANE_SESSION_EPOCH` when re-adding it so old sessions stay revoked. See [Extra frontends](workos-latte-auth.md#extra-frontends).
- `GET /` redirects public visitors to the built-in `GET /docs` OpenAPI documentation page.
- `PANE_STATUS_USERNAME` and `PANE_STATUS_PASSWORD` protect `GET /status` with HTTP Basic auth. Production must set `PANE_STATUS_PASSWORD`; the page sends no-store cache headers.

For the full WorkOS and Latte browser flow, see [WorkOS and Latte Authentication](workos-latte-auth.md).

## Release Metadata

Release metadata is not part of the application `.env` contract. Pane exposes
non-secret build metadata through `GET /api/v1/release`. Local checkouts derive
version/ref/commit from Git when possible. GitHub Actions deploys should cache
release metadata from workflow context. Automatic `main` deployments use
`0.1.0-alpha.<GITHUB_RUN_NUMBER>` as the release version:

```bash
php artisan release:cache --release-version="0.1.0-alpha.$GITHUB_RUN_NUMBER" \
  --ref="$GITHUB_REF_NAME" \
  --commit="$GITHUB_SHA" \
  --built-at="<timestamp>"
```
