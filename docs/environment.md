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

Generate a key (32 random bytes, `base64:` prefix):

```bash
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

Rotating the managed-credential key:

1. Add the new key to `PANE_MANAGED_CREDENTIAL_KEYS`, keeping the old one, and set `PANE_MANAGED_CREDENTIAL_ACTIVE_KEY_ID` to the new key ID. Deploy. New secrets use the new key; old ones still decrypt.
2. Run `php artisan managed-credentials:rotate --dry-run` to see how many secrets still use an older key, then `php artisan managed-credentials:rotate` (repeat, or raise `--limit`, until nothing is left).
3. Run `php artisan managed-credentials:check`: it fails if the active key is missing or if any secret uses a key that is no longer configured.
4. Only then remove the old key from `PANE_MANAGED_CREDENTIAL_KEYS`.

`GET /status` (behind the status password) includes `managed_credentials`: whether the active key is usable and how many secrets are stored, still on an older key, or on a key that is no longer configured. It never includes key material or credentials.

Stored envelopes are bound to their secret record (ID, organization, connection, purpose), so copying an envelope onto another row makes it fail to decrypt. Decrypted credentials are held in `App\Support\ManagedCredentials`, which redacts itself in dumps, strings and JSON and refuses to be serialized, so it can't end up in queued jobs, cache or logs by accident.

## Managed-Database Network Policy

Every managed-database connection (connection tests and runtime queries) is opened by `App\Services\ManagedDatabaseConnector`, the only code allowed to connect to an organization's database. It asks `App\Services\ManagedDatabaseEgressPolicy` first, then connects to one of the addresses that were checked, never re-resolving the hostname, so DNS can't be swapped in between. It also applies the connect timeout and a per-session query timeout.

- Denied by default: loopback, private, shared (CGNAT), link-local, documentation, benchmarking, multicast and reserved ranges, for IPv4 and IPv6, including IPv4-mapped and NAT64 forms. Every DNS answer must pass, so one private answer blocks the host.
- Never allowed: cloud metadata addresses and hostnames (e.g. `169.254.169.254`, `fd00:ec2::254`, `metadata.google.internal`), multicast and unspecified addresses.
- `PANE_MANAGED_DB_EGRESS_ALLOW` is a comma-separated list of approved hosts and ranges, for installation-wide private networks, e.g. `localhost,10.20.0.0/16,fd00:1234::/32`. A hostname rule opens only that exact name; an IP rule opens that address or range. An invalid rule blocks every connection until it's fixed. Organizations can't change these rules.
- `PANE_MANAGED_DB_CONNECT_TIMEOUT` (default 5 s, at most 30) and `PANE_MANAGED_DB_QUERY_TIMEOUT` (default 15 s, at most 120) apply to every organization. The query timeout stops any statement on MariaDB (`max_statement_time`). On MySQL, `max_execution_time` only stops SELECT, so Pane also caps lock waits (`innodb_lock_wait_timeout`, `lock_wait_timeout`) and stops waiting for any reply 2 s after the query timeout. That is not a strict timeout for writes: a MySQL write that is already running may still commit on the server after Pane reports a timeout, and the outcome is then unknown. Mutating queries must not be retried blindly until an operation-status check can reconcile the result.
- Each decision is audited as `managed_database.egress.allowed` or `.denied`, with host, port, resolved addresses and the reason. No credentials are recorded.

On Hostinger, a database on the same server is usually `localhost`: add `localhost` to `PANE_MANAGED_DB_EGRESS_ALLOW`.

### Connections (`/api/v1/organizations/{organization_id}/connections`)

Organization administrators add, test, update, disable (`DELETE`) and reactivate (`PATCH status=active`) their databases. Each active connection uses one slot of the organization's `database_limit`.

- Every create, reactivation, or change to engine, host, port, database, user, TLS mode or password is tested first, and nothing is saved if the test fails.
- The database account must have exactly `SELECT, INSERT, UPDATE, DELETE` on its own database. DDL (`CREATE`, `ALTER`, `DROP`…), `ALL PRIVILEGES`, server-wide, `FILE`, `PROCESS`, `SUPER`, `GRANT OPTION`, roles, or grants on other databases are rejected. A hosting panel grant like `` `u123_shop`.* `` (where `_` is a wildcard) is accepted only when it matches no other database the account can see.
- `tls_mode`: `required` encrypts the connection (the certificate isn't checked, since Pane connects to the IP address); `disabled` only for hosts opened by `PANE_MANAGED_DB_EGRESS_ALLOW`, such as `localhost`; `verify_ca` and `verify_identity` are rejected for now.
- Passwords and certificates are write-only: the API only returns `credentials_configured`. They're stored encrypted (see the managed-credential keys above) and never written to the audit log.

Example account for a same-server database:

```sql
CREATE USER 'pane_app'@'localhost' IDENTIFIED BY '…';
GRANT SELECT, INSERT, UPDATE, DELETE ON `shop`.* TO 'pane_app'@'localhost';
```

## Outgoing Messages

Pane never sends email from a request. It adds a row to the `messages` table (`pane_messages` with the default prefix) and the worker sends it later.

- Types: `system_email` (account and invitation email, always sent) and `marketing_email` (not created anywhere yet; will respect opt-out). The list lives in `App\Models\Message::TYPES` and the column is a plain string, so adding a type needs no migration. `purpose` says what a message is for inside its type (e.g. `invitation`), for templates.
- `status` moves `pending` → `sending` → `sent`, or `failed` after the last retry. `available_at` delays a message or schedules its retry; the worker reads by (`status`, `available_at`).
- `payload` holds the data the template needs. It can contain credentials (an invitation link signs the recipient in), so it is encrypted with `APP_KEY`, hidden from serialization and never logged. Rotating `APP_KEY` makes pending payloads unreadable.
- The subject is stored without an environment prefix; the worker adds `[DEV]` / `[STAGE]` when it sends.
- `organization_id`, `source_type` and `source_id` record what caused the message (e.g. `OrganizationInvitation` and its ID).
- Creating or resending an organization invitation through the v1 API queues one `system_email` with the invitation link, in the same transaction as the invitation. Revoking queues nothing. The API response, including `meta.invitation_url`, is unchanged.

Code adds messages with `App\Services\MessageQueue::push()`, inside the transaction of the change that causes them.

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
- `PANE_EXTRA_FRONTEND_ORIGINS` lists extra frontends this installation trusts besides `FRONTEND_URL`, all in the same configured organization. Entries are separated by `;`, each `origin` or `origin|redirect_uri,redirect_uri`, for example `https://admin.dev.sweegly.co.uk|https://admin.dev.sweegly.co.uk/auth/callback,https://admin.dev.sweegly.co.uk/dashboard`. Without redirect URIs an entry gets `<origin>/auth/callback`. These frontends use the `WORKOS_REDIRECT_URI` path on their own origin and log out to their own root, so add both to WorkOS. Removing an entry disables that frontend and ends its sessions. See [Extra frontends](workos-latte-auth.md#extra-frontends).
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
