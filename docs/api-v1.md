# Pane HTTP API

Status: describes the routes on `main` (rewritten in Pane#188). Planned routes
are listed under "Planned" and are not part of the API until their ticket lands.

> **The OpenAPI contract is not reconciled yet.**
> [`contracts/pane-v1.json`](../contracts/pane-v1.json) still carries the
> operations, schemas and `x-pane-*` rules of the removed organization,
> application, connection, catalog, impersonation and data-row routes. Only the
> routes listed under "Routes" below exist. Where this document and the contract
> differ, this document and the code win. Reconciling the contract and its tests
> (`tests/Unit/Contracts/PaneV1ContractTest.php`) is a follow-up.

Pane is a headless REST API. Each install is one organization; users are `admin`
or `user`. The architecture is in [architecture.md](architecture.md) and the sign-in
sequence in [workos-latte-auth.md](workos-latte-auth.md).

## Protocol

All versioned routes start with `/api/v1` and use JSON. Browsers authenticate with
Pane's Laravel session cookie.

- **Frontend origin.** Pane resolves the calling frontend from the browser's
  `Origin` against the environment (`FRONTEND_URL`, `PANE_EXTRA_FRONTEND_ORIGINS`,
  see [environment.md](environment.md)). `Origin` is required on the CSRF
  bootstrap, the login intent, the callback and every authenticated mutation. A
  missing or unknown value returns `403 application_not_allowed`. Browser code
  never supplies an application id.
- **CSRF.** `POST /api/v1/csrf-cookie` issues the CSRF cookie; mutating
  authenticated requests send it as `X-XSRF-TOKEN`.
- **Request id.** A client may send `X-Request-Id`; Pane returns it when it is a
  UUID and otherwise generates one. It is in the `X-Request-Id` response header
  and in `meta.request_id` or `error.request_id`.
- **Redirects.** `redirect_to` must exactly match a redirect registered for the
  frontend (scheme and host lowercased, default port removed); otherwise
  `422 redirect_not_allowed`.

## Routes

| Route | Auth | Purpose |
| --- | --- | --- |
| `GET /api/v1/release` | public | Application name and release metadata (version, ref, commit, build time) for smoke checks; never environment values |
| `POST /api/v1/csrf-cookie` | origin | Initialise CSRF protection |
| `POST /api/v1/auth/login-intents` | origin | Store the redirect target and return the WorkOS authorization URL and state; body: `redirect_to`, optional `invitation_token`; other fields are rejected |
| `POST /api/v1/auth/callback` | origin | Complete the WorkOS sign-in from `code` and `state` and start the session |
| `GET /api/v1/session` | session | The signed-in `user` (`id`, `email`, `name`, `role`) and the frontend `application` |
| `DELETE /api/v1/session` | session | End the session and return `logout_url`, the WorkOS URL clients must navigate to |
| `GET /api/v1/installation/pane-admin-invitations` | admin | List administrator invitations (cursor paging) |
| `POST /api/v1/installation/pane-admin-invitations` | admin | Invite an administrator by `email`; `201` with a one-time `meta.invitation_url` |
| `DELETE /api/v1/installation/pane-admin-invitations/{invitation_id}` | admin | Revoke an invitation; needs `If-Match` with the invitation's `ETag` |
| `DELETE /api/v1/users/{user_id}` | session | Anonymise a user for personal-data deletion: the user themselves or an admin (below) |
| `GET /api/v1/maps` | session | The maps bootstrap (below) |
| `GET /api/v1/maps/{source}/{table}` | admin | One table map |

Outside `/api/v1`:

- `GET /docs` and `GET /docs/openapi.json` serve the API docs page (see
  [environment.md](environment.md)); `GET /status` is the status page;
- the legacy sign-in routes `/auth/login-url`, `/auth/callback`, `/auth/login` and
  `/auth/user` stay for the current Latte client and follow the same origin,
  redirect, OAuth-state and CSRF rules;
- the legacy CRUD route `/{story}/{subject}` and `/{story}/{subject}/{key}` (below).

### Sign-in

The callback finds or creates the local user from WorkOS and accepts an invitation
when the login intent carried an `invitation_token`. Failures:

- a rejected provider callback, malformed input or an invalid state returns
  `400 invalid_request` without provider details;
- a WorkOS user payload Pane cannot accept (for example no email) returns
  `422 validation_failed`;
- an email with no account and no invitation returns `422 membership_required`
  and creates no session. The error carries `error.logout_url`, the WorkOS logout
  URL, so signing in again shows the account choice;
- invitation problems return `422` with `invitation_invalid`,
  `invitation_expired`, `invitation_revoked`, `invitation_already_accepted`,
  `invitation_email_mismatch` or `invitation_email_unverified`.

### Administrator invitations

Only administrators may call these routes (`403 permission_denied` otherwise).
Pane resolves the invitation expiry from the setting
`pane_admin.invitation_expiry_seconds` (default 24 hours, between 1 hour and 7
days); a caller never chooses it, and the response shows the resolved
`expires_at`. The invitation URL appears only in the creation response; lists and
reads never carry a token. Creation and revocation are audited. The first
administrator is created from the server with
`php artisan pane:bootstrap-admin <email>`.

### Anonymising a user

`DELETE /api/v1/users/{user_id}` (the user's `uuid`) anonymises a user for
personal-data deletion. A user may do it to themselves (their session ends); an
active administrator may do it for anyone, for a request received by email.
Success is `204`.

The user row stays, so row ownership and audit actor ids keep working, but it
loses its name, email, WorkOS link and details (it becomes `anon-<random>`), is
deactivated, and can't sign in. Pane deletes the WorkOS user first, then in one
transaction blanks the user, revokes and redacts the user's invitations
(pending ones are revoked) and replaces the email with `[redacted]` in audit
`metadata`. Nothing maps the old identity to the new one: inviting the same
email later creates a new user. The audit event `user.anonymise` carries only
the anonymised user's id.

- It is idempotent: an anonymised user returns `204` again without calling WorkOS.
- If WorkOS fails, nothing changes in Pane and the call returns
  `503 dependency_unavailable`; repeat it. A WorkOS user that no longer exists
  counts as deleted.
- The last active administrator can't be anonymised (`409 operation_conflict`).
- Anyone else gets `403 permission_denied`; an unknown id is `404` for an
  administrator and `403` for everyone else.

Audit rows stay append-only: they can't be deleted, and the only column that may
change after the insert is `metadata`, which is how erasure redacts an email.

### Maps

`GET /api/v1/maps` returns the bootstrap Latte builds its shell from: the menu
`version`, the `menu` items (`key`, `label`, `icon`, a `target` that is a table map
(`source` and `table`) or a `function` key, an optional `parent` key for one level
of nesting, and `position`), the switchable `functions` as `{key, enabled}`
(`records`, `members`, `dashboard`), the public `settings` (empty until settings
are declared in maps) and the `tables` the caller can see.

`GET /api/v1/maps/{source}/{table}` returns one table map: its `version`, `ui`
and mapped `fields` in map order.

Both answers send a strong `ETag` and `Cache-Control: no-store`. Paths and bodies
use stable map names, never physical table or column names, and unmapped tables
and fields never appear. Administrators see every app table map plus the system
maps (Pane's own tables) with `system: true`, read-only. A `user` gets the menu,
functions and public settings; table entries and menu items that point at tables
are left out, and a table map answers `404 resource_not_found`, like an unmapped
one.

Maps get into an install with `php artisan app:install`
([app-install.md](app-install.md)); there is no route that writes them yet.

### Legacy CRUD route

The unversioned Story/Action/Mapper route serves the install's tables behind the
`auth` middleware: `GET`/`POST /crud/{subject}` and `GET`/`PUT`/`DELETE`
`/crud/{subject}/{key}`. Any signed-in user may read non-system subjects; only
administrators may create, change or delete rows and read system subjects. It is
driven by the legacy `map_*` tables, has no row ownership, and uses its own
response envelope. See [crud-authentication.md](crud-authentication.md). No new
subject or capability is added to it other than through the planned map-driven
CRUD work.

## Success, pagination and concurrency

```json
{"data":{"id":"..."},"meta":{"request_id":"..."}}
```

```json
{
  "data":[{"id":"..."}],
  "meta":{"request_id":"...","page":{"next_cursor":"...","has_more":true}}
}
```

Collections use opaque cursor paging with `page[cursor]` and `page[limit]`
(default 25, 1 to 100); clients must not build cursors. Mutable resources use a
strong quoted `ETag` and `If-Match`: a missing precondition is
`428 precondition_required`, a malformed, weak or wildcard value is
`400 invalid_request`, and a stale one is `412 version_conflict`. `POST` returns
`201` or `202`, `DELETE` `204`, reads `200`.

## Errors

```json
{
  "error": {
    "code": "validation_failed",
    "message": "The request could not be processed.",
    "details": {"fields":{"name":["required"]}},
    "request_id": "019..."
  }
}
```

`details` is optional. Clients branch on `error.code`, never on `message`.
Production messages never contain SQL, credentials, hosts, paths or traces.

| Status | Codes used by the routes above |
| --- | --- |
| 400 | `invalid_request`, `invalid_cursor`, `invalid_identifier` |
| 401 | `authentication_required` |
| 403 | `application_not_allowed`, `permission_denied`, `csrf_failed` |
| 404 | `resource_not_found` |
| 409 | `operation_conflict`, `provider_session_missing` |
| 412 | `version_conflict` |
| 422 | `validation_failed`, `redirect_not_allowed`, `membership_required`, the `invitation_*` codes above |
| 428 | `precondition_required` |
| 429 | `rate_limited` |
| 500 | `internal_error` |
| 503 | `dependency_unavailable` |

## Planned

Each item has its own ticket and is added here when it lands:

- invitations for users and the sign-up setting (Pane#183);
- the maps admin API: edit a map's fields, validation and views with `If-Match`,
  audit and history, never tables (Pane#157);
- map-driven CRUD with access through a members table (Pane#185, Pane#194);
- native-client sign-in (Pane#130).
