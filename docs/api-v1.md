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
| `GET /api/v1/installation/user-invitations` | admin | List user invitations (cursor paging) |
| `POST /api/v1/installation/user-invitations` | admin | Invite a user by `email`; `201` with a one-time `meta.invitation_url`. The link is also emailed (plain text); `meta.email_sent` is `false` when sending failed, and the invitation is kept |
| `POST /api/v1/installation/user-invitations/{invitation_id}/resend` | admin | Issue a fresh link for a pending invitation and email it; no new row, the old link stops working; `409` when not pending |
| `DELETE /api/v1/installation/user-invitations/{invitation_id}` | admin | Revoke a user invitation; needs `If-Match` with the invitation's `ETag` |
| `DELETE /api/v1/users/{user_id}` | session | Anonymise a user for personal-data deletion: the user themselves or an admin (below) |
| `GET /api/v1/maps` | session | The maps bootstrap (below) |
| `GET /api/v1/maps/{source}/{table}` | admin | One table map |
| `PUT /api/v1/maps/{source}/{table}` | admin | Create or replace a table map (maps admin API, below) |
| `GET /api/v1/maps/{source}/{table}/versions` | admin | The table map's history |
| `POST /api/v1/maps/{source}/{table}/versions/{version}/restore` | admin | Save an old version again as a new one |
| `GET /api/v1/maps/menu` | admin | The whole menu, with its `ETag` |
| `PUT /api/v1/maps/menu` | admin | Replace the whole menu |
| `GET /api/v1/maps/menu/versions` | admin | The menu's history |
| `POST /api/v1/maps/menu/versions/{version}/restore` | admin | Save an old menu version again as a new one |
| `PATCH /api/v1/maps/functions/{key}` | admin | Switch one function on or off |
| `GET /api/v1/settings` | admin | Every declared app setting with its value and version |
| `GET /api/v1/settings/{key}` | admin | One setting; the `ETag` is its value version |
| `PUT /api/v1/settings/{key}` | admin | Set a value (`{"value": ...}`, `If-Match` required); an image key can only be cleared with `null` |
| `POST /api/v1/settings/{key}/upload` | admin | Upload an image (multipart field `file`, `If-Match` required) |

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

### User invitations

Same shape and rules as administrator invitations, with `role: user`, in their
own table (`user_invitations`) and routes. Only administrators call them; a
standard user gets `403 permission_denied`. Inviting an email that already belongs
to an active user is `409 operation_conflict`. Expiry uses the same setting as
administrator invitations. The invitation URL carries the same `invitation_token`;
Pane looks the token up among user invitations first, then administrator ones.
Accepting a user invitation creates a standard user (or reactivates an existing
standard user) and never changes an administrator: an invitation for an
administrator's email is refused with `invitation_invalid`.

### Open sign-up

`pane.signup.open` (environment `PANE_SIGNUP_OPEN`, default `false`) is a deploy
setting, not an API. When it is on, the callback creates a standard user for an
unknown email that WorkOS verified, with no invitation. When it is off, an unknown
email gets `membership_required`. Administrators are invitation-only either way,
and a deactivated user stays refused.

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
([app-install.md](app-install.md)) or through the admin API below.

#### Maps admin API

Only `admin` users may call these routes; a `user` gets `403 permission_denied`.
Editing a map never creates, alters or drops a table: table structure comes from
migrations, and no route here runs DDL. System maps (Pane's own tables) answer
`409 system_map_protected`, even for administrators. Every edit, restore and switch
writes an `audit_events` row (`maps.table.save`, `maps.table.restore`,
`maps.menu.save`, `maps.menu.restore`, `maps.function.switch`) with the actor, the
subject and the from and to versions; the full diff stays in `maps_versions`.

- **Table map.** `PUT /api/v1/maps/{source}/{table}` replaces the whole map:
  `description`, `ui` and `fields` (each with `name`, `type`, `validations`, `ui`,
  ...), validated like `MapStore` does. The table and every non-computed field must
  exist in the database (`422 validation_failed`, with a `fields` object of path to
  message). To change an existing map send `If-Match` with the `ETag` of
  `GET /api/v1/maps/{source}/{table}`; to create the map of a table that is not mapped
  yet send `If-None-Match: *` (`201`). The `name` in the body, when present, must match
  the path. There is no delete: a map can only be emptied of fields.
- **History.** `GET .../versions` lists `version`, `author`, `restored_from`, `diff` and
  `created_at`, newest first. `POST .../versions/{version}/restore` needs `If-Match` with
  the current `ETag` and saves that snapshot as a new version.
- **Menu.** `GET /api/v1/maps/menu` returns `{version, items}` with the `ETag`
  `"menu-<version>"`. `PUT` replaces all items (`key`, `label`, optional `icon`, `target`,
  optional `parent`, `position`) with `If-Match`; the list can't be empty, keys are
  unique, a `table` target must be a mapped table, a `function` target a known function,
  and only one level of nesting is allowed. The menu is versioned as the subject `menu`,
  with the same history and restore routes.
- **Functions.** `PATCH /api/v1/maps/functions/{key}` with `{"enabled": true|false}`
  and `If-Match` set to `"<key>-on"` or `"<key>-off"` (the state the caller saw; the
  response carries the new `ETag`). Switches are audited but not versioned.

Error codes: `400 invalid_request` (malformed `If-Match`), `404 resource_not_found`,
`412 version_conflict`, `428 precondition_required`.

### Legacy CRUD route

The unversioned Story/Action/Mapper route serves the install's tables behind the
`auth` middleware: `GET`/`POST /crud/{subject}` and `GET`/`PUT`/`DELETE`
`/crud/{subject}/{key}`. Any signed-in user may read non-system subjects; only
administrators may create, change or delete rows and read system subjects. It is
driven by the legacy `map_*` tables, has no row ownership, and uses its own
response envelope. See [crud-authentication.md](crud-authentication.md). No new
subject or capability is added to it other than through the planned map-driven
CRUD work.

On the CRUD route a `POST`/`PUT` that names a column the table's map doesn't
declare is refused with `422` and nothing is written (keys starting with `_` are
ignored). A list `GET` accepts `filter[<field>]=<value>` (equality on any mapped
field except passwords, several filters combine with AND); a filter on an
unknown field is a `400`. Filters apply inside the members access rule, and
`pagination.total` counts the filtered rows.

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

- map-driven CRUD with access through a members table (Pane#185, Pane#194);
- native-client sign-in (Pane#130).

## App settings (Pane#158)

Typed app settings are declared in `maps_settings` (`key`, `type` of `flag`, `text`, `number` or `image`,
`default`, `public`, `editable_by` of `administrator` or `pane_admin`, `label`, `help`). The whole list is
replaced as one versioned subject (`settings` in `maps_versions`) with `php artisan settings:declare <file.json>`;
editing declarations in Latte comes with the maps editors (Pane#157). Values are one per key for the whole
install (`maps_setting_values`), each with its own version. Pane's own code-registered settings are a separate thing.

- Public settings (`public: true`) are served to every signed-in user in the `settings` list of `GET /api/v1/maps`
  as `{key, type, value}`; the value is the default until one is set.
- Reading all settings and writing values is for administrators (403 otherwise); both `editable_by` levels
  resolve to the install's administrator until organizations come back. `PUT` and the upload answer 412
  `version_conflict` for a stale `If-Match`, 422 `validation_failed` for a value that does not fit the type
  (a `flag` is true or false, a `text` at most 2000 characters, a `number` a finite number), and 404 for a key
  that is not declared. Every change is audited (`settings.value.set`, `settings.declare`) without the value.
- Image uploads go to the `r2` disk (see [environment.md](environment.md)): PNG, JPEG or WebP, at most 2 MB,
  type read from the file's content, no SVG. The value stores `path`, `url`, `mime` and `size`. Old objects are kept.
- `text` values are a plain string for now; the value column is JSON so one text per language can come later.
