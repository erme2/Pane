# Pane Phase-One HTTP API

Status: phase-one contract

The machine-readable companion to this document is the OpenAPI 3.1 document
[`contracts/pane-v1.json`](../contracts/pane-v1.json). It is the shared contract
for Pane, Latte, and Burro and can drive client generation and request/response
validation. A route is not part of v1 unless it appears there.

## Protocol

All v1 routes start with `/api/v1`, use JSON, and use UUIDs for installation
resources, organizations, applications, memberships, connections, catalog
objects, invitations, impersonation sessions, and audit events. Managed row
keys are JSON strings because the connected table, rather than Pane, defines
their scalar type.

Pane derives the application from the browser's `Origin` when a login intent is
created, then stores that immutable application UUID in Pane's server-side
session. Every authenticated request reloads the active registration by that
UUID so origin removal or application disabling takes effect immediately.
Mutating requests require an `Origin` matching the session application; reads
validate it when present because browsers do not guarantee `Origin` on
same-origin `GET` or `HEAD` requests. Browser JavaScript never supplies an
application identifier.

OpenAPI represents this trust input explicitly. `Origin` is required on the
CSRF bootstrap, login intent, callback, and every authenticated mutation, and
optional on authenticated reads. The browser controls this forbidden header and
the trusted proxy preserves it; generated clients document it but browser code
does not synthesize it. Origin middleware receives the raw transport string
before OpenAPI parameter validation. On a required operation, a missing,
opaque `null`, malformed, unregistered, or session-mismatched value returns
`403 application_not_allowed`. On a read, absence continues with the immutable
server-session application, while an invalid or mismatched supplied value
returns the same 403. Before authentication a valid registered origin resolves
the application. After Pane binds an application UUID into the server-side
session, `Origin` is comparison-only and can never select another application.
The structured order, outcomes, and examples are normative in
`x-pane-origin-validation`.

Every trusted origin is globally unique among active registrations in one Pane
installation. Registration and origin updates reject a duplicate with
`409 duplicate_resource`. Login intents reject a missing, opaque, `null`, or
unregistered origin. Proxies preserve `Origin` and remove any client-supplied
application-identity header.

Pane stores a trusted origin only in normalized serialized-origin form:
scheme, lowercase host, and an optional non-default port, with no credentials,
path, query, or fragment. HTTPS is mandatory except for loopback local/test
registrations. Global uniqueness is checked after lowercasing scheme and host
and removing a default port. Registered redirects use the normalization and
validation rules below when they are written, not only when they are used.
OpenAPI separates permissive registration/candidate inputs from canonical
stored outputs. Inputs may contain an uppercase scheme or host, an explicit
default port, or an empty path; Pane normalizes them before validation and
storage. Ports must be integers from 1 through 65535. Responses expose only the
canonical schemas. `x-pane-uri-normalization-examples` contains normative
accepted, rejected, and canonical vectors shared by Pane and Latte.

Burro uses an installation-scoped registration without an organization. A
Latte-derived registration has exactly one immutable `organization_id`.
Changing that binding requires replacing the registration, and a registration
cannot be deleted while it has active sessions. Neither a query parameter,
cookie, callback value, request body, route value, nor browser-controlled header
can select or change the application organization.

`PATCH /installation/applications/{application_id}` changes registration status
between `active` and `disabled` under `If-Match`. Disabling atomically
invalidates the application's sessions and impersonations and releases its
canonical origin from active-registration uniqueness. Re-enabling reloads and
validates the complete registration, requires the canonical origin to remain
available, and returns `409 duplicate_resource` on a conflict. Disabled-session
cookies never become valid again after re-enabling.

Clients may send any transport-valid value in `X-Request-Id`. Pane returns it
only when it is a valid UUID; every other value is ignored and replaced with a
generated UUID. The UUID is returned in the response header and included in
`meta.request_id` or `error.request_id`. Request-ID middleware applies this rule
before API schema validation and does not reject a request because of that
header. Response request IDs are always UUIDs.

Browser requests use Pane's Laravel session cookie. `POST /csrf-cookie` issues
the CSRF cookie from an Origin-validated request; mutating authenticated requests use the existing
`X-XSRF-TOKEN` contract. Responses containing secrets are never defined:
connection passwords and private certificate material are write-only.

## Resolution order

Pane performs these checks in order and stops at the first failure:

1. establish or generate the request ID;
2. resolve the active application from the login origin or authenticated
   server-side session and revalidate its registration;
3. authenticate the Pane session and apply CSRF validation when required;
4. compare `{organization_id}` with the application's fixed organization;
5. load the organization and require its active state;
6. load the caller's active membership and required role;
7. resolve the organization-owned resource using both its UUID and
   `organization_id`;
8. enforce connection grant, catalog allowlist, operation, row ownership, and
   concurrency policy as applicable.

Steps 4 through 6 happen before any organization-owned resource query. A
wrong-organization resource UUID therefore cannot reveal whether that resource
exists. Such requests return `404 resource_not_found`, while a route
organization that differs from the application's fixed organization returns
`403 organization_context_mismatch` without loading the organization.

Burro's `/installation/*` routes require a Pane administrator and do not accept
an organization context. Pane administrators cannot use organization routes
directly. An active Burro impersonation fixes one target organization and
effective membership in server-side session state. For a Burro request to an
organization route, Pane compares the route organization with that immutable
impersonation target before resolving the organization. A normal Latte-derived
request instead compares it with the application's immutable organization.
Impersonation cannot be initiated, retargeted, or renewed from an organization
route and ends on explicit deletion, logout, expiry, target membership
suspension, or organization suspension/closure.

## Route families

### Shared session

- `POST /csrf-cookie` initializes CSRF protection from a request whose browser
  `Origin` identifies the application. This bootstrap route does not itself
  require a CSRF token.
- `POST /auth/login-intents` stores redirect and optional invitation intent in
  Pane's session and returns the WorkOS authorization URL and OAuth state.
- `POST /auth/callback` completes WorkOS authentication. If the login intent
  contains an invitation token, Pane atomically validates the token, verified
  WorkOS email, application organization, and invitation state before creating
  or reactivating the membership. The token is never returned or put in a URL.
  Invitation acceptance failures return safe public `422` codes:
  `invitation_invalid`, `invitation_expired`, `invitation_revoked`,
  `invitation_already_accepted`, `invitation_email_mismatch`, or
  `invitation_organization_mismatch`. Generic malformed callback input remains
  `validation_failed`. Clients render invitation outcomes from `error.code`;
  callback rejection details are absent or empty and must not expose invitation
  tokens, organization identifiers, target emails, or identity-provider data.
- `GET /session` returns the real actor, effective user, application, fixed
  organization (if any), membership (if any), and active impersonation state.
- `DELETE /session` logs out and invalidates the Pane session.

### Installation scope (Burro)

- `/installation/organizations` and
  `/installation/organizations/{organization_id}` manage organization
  lifecycle and database limits.
- `/installation/applications` and
  `/installation/applications/{application_id}` manage registrations, trusted
  origins, redirects, and immutable fixed-organization binding.
- `/installation/pane-admin-invitations` manages Pane-admin invitations.
- `/installation/impersonations` creates support sessions;
  `/installation/impersonations/{impersonation_id}` ends one.
- `/installation/audit-events` lists installation audit history.

### Organization scope (Latte-derived applications)

- `/organizations/{organization_id}/memberships` and child membership routes
  manage membership state and roles.
- `/organizations/{organization_id}/invitations` and child invitation routes
  create, revoke, and resend organization invitations.
- `/organizations/{organization_id}/connections` and child connection routes
  manage metadata and write-only credentials; `/test` tests reachability and
  privileges, and `/catalog-refreshes` starts discovery.
- `/organizations/{organization_id}/connections/{connection_id}/grants`
  manages Viewer, Editor, and Manager grants.
- `/organizations/{organization_id}/connections/{connection_id}/catalog`
  reads discovered metadata; catalog object description updates use `If-Match`.
- `/organizations/{organization_id}/connections/{connection_id}/tables/{table_id}/rows`
  and `.../rows/{row_key}` expose allowlisted, parameterized owned-row CRUD.
- `/organizations/{organization_id}/audit-events` lists organization-visible
  audit history.

The exact parameters, request and response schemas, required headers, method
matrix, and status codes are in the OpenAPI contract.

`GET /session` has three discriminated modes and avoids repeating relational
identifiers that could disagree. A `latte` session returns one `user`, its
application projection, fixed organization, and membership projection. A
`burro_installation` session returns one `user` and its application. A
`burro_impersonation` session returns actor, effective user, application,
organization, membership projection, and impersonation projection. The
session-only application, membership, and impersonation projections omit the
organization/user/actor identifiers already fixed by their enclosing variant;
the enclosing structure is authoritative and therefore cannot encode a
cross-context mismatch.

Every registered redirect and `redirect_to` value must be an absolute URI with
no credentials or fragment. HTTPS is required except for loopback local/test
registrations. Pane normalizes both the stored allowlist value and candidate by
lowercasing scheme and host, removing a default port, and changing an empty path
to `/`; path and query remain significant. Registration rejects invalid values
and normalized duplicates. `redirect_to` must then exactly match one normalized
URI on the active application's allowlist. A mismatch returns
`422 redirect_not_allowed`; Pane never redirects to a partially matched origin,
suffix, wildcard, or caller-derived fallback.

Invitation creation never accepts a caller-selected expiry. Pane-admin
invitations resolve the installation setting; organization invitations resolve
the organization override, then installation setting, then versioned default.
The response exposes the resolved `expires_at`. Exact configurable bounds remain
a Pane-admin product decision and are not hard-coded into the HTTP contract.

## Success, pagination, and concurrency

Every successful response is one of:

```json
{"data":{"id":"..."},"meta":{"request_id":"..."}}
```

```json
{
  "data":[{"id":"..."}],
  "meta":{"request_id":"...","page":{"next_cursor":"...","has_more":true}}
}
```

Collections use opaque cursor pagination with `page[cursor]` and
`page[limit]`. The default limit is 25 and the allowed range is 1–100. Clients
must not parse or construct cursors.

Mutable resources return an `ETag`. Update, delete, restore, role, description,
and lifecycle operations require that value in `If-Match`. V1 uses one quoted
strong opaque-tag grammar for both headers; weak validators and `*` are
forbidden. A missing precondition returns `428 precondition_required`, malformed,
weak, or wildcard input returns `400 invalid_request`, and a syntactically valid
stale value returns `412 version_conflict`. `x-pane-etag` publishes normative
accepted/rejected vectors. Create operations and action requests that do not
update an existing resource do not require `If-Match`.

`POST` creates a resource or starts an auditable action and returns `201` or
`202` as declared by the fixture. `PATCH` returns `200`, `DELETE` returns `204`,
and successful reads return `200`.

## Errors

Errors have one stable shape:

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

`details` is optional and machine-readable. Each OpenAPI operation declares its
exact error statuses and references a response schema whose machine-code enum is
limited to that operation. The top-level `x-pane-operation-errors` matrix is the
machine-readable source of truth and contract tests require every response enum
to match it exactly. The shared `500 internal_error` and
`503 dependency_unavailable` responses are declared on every operation; Pane
may return them only after request correlation has been established and always
uses the safe error envelope.
Production messages are safe and
do not contain SQL, credentials, internal hosts, source paths, stack traces, or
unfiltered upstream errors. Stable v1 codes are:

| Status | Codes |
| --- | --- |
| 400 | `invalid_request`, `invalid_cursor`, `invalid_identifier` |
| 401 | `authentication_required`, `session_expired` |
| 403 | `application_not_allowed`, `organization_context_mismatch`, `organization_inactive`, `membership_required`, `permission_denied`, `csrf_failed`, `impersonation_required` |
| 404 | `resource_not_found` |
| 409 | `quota_exceeded`, `duplicate_resource`, `table_contract_incompatible`, `operation_conflict` |
| 412 | `version_conflict` |
| 422 | `validation_failed`, `connection_policy_rejected`, `connection_test_failed`, `redirect_not_allowed`, `invitation_invalid`, `invitation_expired`, `invitation_revoked`, `invitation_already_accepted`, `invitation_email_mismatch`, `invitation_organization_mismatch` |
| 428 | `precondition_required` |
| 429 | `rate_limited` |
| 500 | `internal_error` |
| 503 | `dependency_unavailable` |

Clients may branch on `error.code`; they must not branch on `message`.

Schema and parameter parsing failures are normalized before handler execution.
Malformed pagination limits, filter/sort syntax, and `If-Match` values return
`400 invalid_request`; malformed cursors return `400 invalid_cursor`; malformed
UUID path values, catalog identifiers, and base64url row keys return
`400 invalid_identifier`. An operation declares the union of only the codes its
parameters can produce. A syntactically valid but absent or deliberately
concealed resource remains `404 resource_not_found`.

## Identifier and data rules

Requests contain structured JSON values only. Pane rejects unknown writable
fields. Table and column identifiers come exclusively from catalog UUIDs and
server-side discovered names. No endpoint accepts SQL, database credentials on
read, physical table names as route parameters, arbitrary sort expressions, or
browser-selected organization switching.

Row list query parameters are limited to the structured `filter`, `sort`,
`page[cursor]`, and `page[limit]` schemas in OpenAPI. Pane turns catalog column
UUIDs into quoted allowlisted identifiers and uses parameterized values.
Standard users see only rows whose immutable `pane_membership_id` matches their
effective membership. Create rejects client-supplied ownership and server
timestamp columns.

Managed row keys use an RFC 4648 base64url encoding without padding in
`{row_key}`. Pane decodes the value to the table's declared scalar primary-key
type and never treats it as an identifier or SQL fragment.

Connection create and update bodies contain a nested write-only `credentials`
object. Response schemas omit that object entirely and expose only
`credentials_configured`. Grants are individual idempotent membership
resources at `.../grants/{membership_id}`; `PUT` sets one preset and `DELETE`
removes it.

## Compatibility and removal policy

The existing `/{story}/{subject}` and `/{story}/{subject}/{key}` routes are
unversioned transitional routes. They may coexist with `/api/v1` while v1
services are implemented, but they are not aliases and v1 clients must never
fall back to them.

During migration, the current `/auth/login-url`, `/auth/callback`, and
`/auth/user` routes remain compatibility endpoints for the current Latte
client. Their v1 replacements are `/api/v1/auth/login-intents`,
`/api/v1/auth/callback`, and `/api/v1/session`. New invitation activation is
implemented only in v1. The compatibility endpoints follow the same origin,
redirect, OAuth-state, safe-error, session, and CSRF invariants and are removed
under the policy below after Latte migrates.

Migration proceeds endpoint family by endpoint family:

1. implement the v1 family behind its contract tests;
2. migrate Latte/Burro and record usage telemetry without request bodies;
3. announce at least one tagged release of deprecation;
4. disable the corresponding dynamic subject by default;
5. remove it in the next major release after no supported frontend uses it.

No new subject or capability may be added to the dynamic routes. Security fixes
continue during coexistence. Removing a dynamic family requires tests proving
the v1 replacement, authorization denials, and absence of that legacy route.
