# Cross-App Release Gate

This is the single checklist a coordinated Pane + Latte release must pass before
it is marked ready. It covers the first alpha topology (Pane at
`https://pane.erme2.com`, Latte at `https://latte.erme2.com`) and applies the
same way to any other installation pair, such as the Sweegly dev installs
deployed from erme2/deploy: replace the origins with that pair's.

A release is **ready** only when every item below is checked. Copy the
checklist into the release notes (see the template in
[Release Policy](release.md#first-alpha-release-notes-template)) and tick each
item there. One unchecked or failing item means the release is not ready: fix
it, or record why the release is held, and run the gate again. Never mark an
item as passed on the strength of the other repository's tag or CI run.

Record only pass/fail results and non-secret values (origins, versions,
commits). Never paste secret values into the release notes, tickets or logs.

## 1. Pane and Latte Trust Each Other

- [ ] Pane `FRONTEND_URL` is the Latte production origin
  (`https://latte.erme2.com`), with no path and no trailing slash. Pane uses it
  for CORS and v1 session application projection.
- [ ] Every other frontend that should reach this Pane is listed in
  `PANE_EXTRA_FRONTEND_ORIGINS`, and nothing else is.
- [ ] `APP_URL` is Pane's canonical HTTPS origin and `TRUSTED_HOSTS` only lists
  extra Pane hostnames (never a Latte hostname).
- [ ] Latte's deployed `latte-config.json` has `paneBaseUrl` set to the Pane
  production URL, `expectedOrigin` set to the Latte origin, and the application
  and organization IDs registered in Pane (see Latte's
  `docs/hostinger-hosting.md`).

## 2. WorkOS Redirects Match Latte

- [ ] Pane `WORKOS_REDIRECT_URI` is Latte's callback,
  `https://latte.erme2.com/auth/callback`.
- [ ] Pane `LATTE_REDIRECT_URIS` lists exactly the Latte return URLs login may
  use, normalized, all on the Latte origin.
- [ ] `WORKOS_RETURN_TO` is unset (logout returns to the `FRONTEND_URL` root) or
  is the Latte root.
- [ ] The WorkOS dashboard lists the same redirect URI and logout return URL for
  this environment, plus those of every `PANE_EXTRA_FRONTEND_ORIGINS` entry.

## 3. Session Cookies Fit the Topology

- [ ] `APP_ENV=production`, `APP_DEBUG=false` and `SESSION_SECURE_COOKIE=true`
  (Pane refuses to boot otherwise).
- [ ] Pane and Latte are both served over HTTPS with valid certificates.
- [ ] `SESSION_DOMAIN` is unset (host-only cookie on the Pane host) unless the
  chosen topology deliberately shares the cookie, and the reason is written in
  the release notes.
- [ ] A browser login from Latte reaches the dashboard, a reload keeps the
  session, and logout ends it.

## 4. Pane Health

- [ ] `./bash/hostinger-preflight.sh` passes on the host (it checks the
  production keys, safety settings, managed-credential key syntax and database
  connectivity without printing values).
- [ ] `php artisan managed-credentials:check` passes, and `GET /status`
  reports `managed_credentials` as usable with nothing on a missing key.
- [ ] `GET /status` reports the message worker as `ok` (see
  [Message Worker (Cron)](hostinger.md#message-worker-cron)).

## 5. Release Checks

- [ ] Tests: Pane `php artisan test` and Latte `npm test` pass on the released
  commits.
- [ ] Static analysis: Pane `composer analyse` and Latte `npm run lint` pass;
  Latte `npm run build` succeeds.
- [ ] Dependency and security audit: `composer audit` (Pane) and `npm audit`
  (Latte) have no unaccepted advisories; accepted ones are listed in the release
  notes with the reason.
- [ ] Migrations reviewed: every migration since the previous release was read,
  and anything destructive or slow is called out in the release notes.
- [ ] Secrets generated or rotated: `APP_KEY`, managed-credential keys, WorkOS
  keys, database password and `PANE_STATUS_PASSWORD` are unique to this
  environment and not placeholders; the protected GitHub Environment secrets
  match the password-manager copy.
- [ ] Backups confirmed: a database backup was taken before the release's
  migrations and can be restored (rollback steps are tracked by Pane #99).
- [ ] Smoke checks passed: the deploy's Pane smoke checks (`/`,
  `/docs/openapi.json`, `/api/v1/release`, `/api/v1/session`,
  `/api/v1/installation/applications`) and Latte's smoke checks passed, and the
  versions reported by `GET /api/v1/release` and `/latte-release.json` match the
  release pair in the notes.

## Required Production Environment

### Pane (`.env` on the host, from the protected GitHub Environment secret)

| Variable | Gate rule |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_KEY` | Unique per environment, generated on the host. |
| `APP_URL` | Pane's canonical HTTPS origin. |
| `TRUSTED_HOSTS` | Extra Pane hostnames only; may be empty. |
| `SESSION_SECURE_COOKIE` | `true` |
| `PANE_STATUS_PASSWORD` | Strong random password. |
| `PANE_ENVIRONMENT` | `dev` or `stage` on non-production installs; `prod` or unset on production. |
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Production database; real password. |
| `DB_TABLE_PREFIX` | The installation's system-table prefix. |
| `PANE_MANAGED_CREDENTIAL_ACTIVE_KEY_ID`, `PANE_MANAGED_CREDENTIAL_KEYS` | Active key present in the key set; separate from `APP_KEY`. |
| `PANE_MANAGED_DB_EGRESS_ALLOW` | Only approved hosts (usually `localhost` on Hostinger). |
| `WORKOS_API_KEY`, `WORKOS_CLIENT_ID`, `WORKOS_PROVIDER`, `WORKOS_ORGANIZATION_ID`, `WORKOS_CONNECTION_ID` | This environment's WorkOS values, not placeholders. |
| `FRONTEND_URL` | Latte production origin. |
| `LATTE_REDIRECT_URIS` | Exact Latte return URLs. |
| `WORKOS_REDIRECT_URI` | Latte's `/auth/callback`. |
| `WORKOS_RETURN_TO` | Unset or the Latte root. |
| `PANE_EXTRA_FRONTEND_ORIGINS` | Other trusted frontends of this install, or empty. |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Working mail account for invitation email. |

See [Environment Configuration](environment.md) for what each variable does.

### Latte (static build, no server-side environment)

Latte reads its runtime settings from the public `/latte-config.json`, not from
environment variables; the `VITE_PANE_PROXY_*` variables are for local
development only.

| Setting | Gate rule |
| --- | --- |
| `paneBaseUrl` | Pane production URL. |
| `expectedApplicationId` | Application UUID registered in Pane. |
| `expectedOrganizationId` | Organization UUID registered in Pane. |
| `expectedOrigin` | Latte production origin. |

`latte-config.json` is public: it must never contain secrets.
