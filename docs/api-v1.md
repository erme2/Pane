# Pane Phase-One HTTP API

Status: phase-one contract

The machine-readable companion to this document is
[`contracts/pane-v1.json`](../contracts/pane-v1.json). That file is the shared
contract fixture for Pane, Latte, and Burro. A route is not part of v1 unless it
appears there.

## Protocol

All v1 routes start with `/api/v1`, use JSON, and use UUIDs for installation
resources, organizations, applications, memberships, connections, catalog
objects, invitations, impersonation sessions, and audit events. Managed row
keys are JSON strings because the connected table, rather than Pane, defines
their scalar type.

Latte sends its registered application UUID in `X-Pane-Application-Id` on every
request. Pane also validates the request `Origin` against that registration.
Burro uses an installation-scoped registration without an organization. A
Latte-derived registration has exactly one immutable `organization_id`.
Neither a query parameter, cookie, callback value, request body, nor route value
can select or change that organization.

Clients may send a UUID in `X-Request-Id`. Pane returns that value when valid or
generates a UUID, returns it in the same response header, and includes it in
`meta.request_id` or `error.request_id`. Invalid request IDs are replaced, not
reflected.

Browser requests use Pane's Laravel session cookie. Mutating requests also use
the existing `X-XSRF-TOKEN` contract. Responses containing secrets are never
defined: connection passwords and private certificate material are write-only.

## Resolution order

Pane performs these checks in order and stops at the first failure:

1. establish or generate the request ID;
2. validate the registered application and trusted origin;
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
directly; they must create an impersonation session and then satisfy the same
fixed-application and organization checks as the effective membership.

## Route families

### Shared session

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

The exact method matrix and status codes are in the contract fixture.

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
and lifecycle operations require that value in `If-Match`. A missing precondition
returns `428 precondition_required`; a stale value returns
`412 version_conflict`. Create operations and action requests that do not update
an existing resource do not require `If-Match`.

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

`details` is optional and machine-readable. Production messages are safe and
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
| 422 | `validation_failed`, `connection_policy_rejected`, `connection_test_failed` |
| 428 | `precondition_required` |
| 429 | `rate_limited` |
| 500 | `internal_error` |
| 503 | `dependency_unavailable` |

Clients may branch on `error.code`; they must not branch on `message`.

## Identifier and data rules

Requests contain structured JSON values only. Pane rejects unknown writable
fields. Table and column identifiers come exclusively from catalog UUIDs and
server-side discovered names. No endpoint accepts SQL, database credentials on
read, physical table names as route parameters, arbitrary sort expressions, or
browser-selected organization switching.

Row list query parameters are limited to catalog column UUID filters,
`page[cursor]`, `page[limit]`, and a catalog column UUID sort with `asc` or
`desc`. Pane turns them into quoted allowlisted identifiers and parameterized
values. Standard users see only rows whose immutable `pane_membership_id`
matches their effective membership. Create ignores and rejects client-supplied
ownership and server timestamp columns.

## Compatibility and removal policy

The existing `/{story}/{subject}` and `/{story}/{subject}/{key}` routes are
unversioned transitional routes. They may coexist with `/api/v1` while v1
services are implemented, but they are not aliases and v1 clients must never
fall back to them.

Migration proceeds endpoint family by endpoint family:

1. implement the v1 family behind its contract tests;
2. migrate Latte/Burro and record usage telemetry without request bodies;
3. announce at least one tagged release of deprecation;
4. disable the corresponding dynamic subject by default;
5. remove it in the next major release after no supported frontend uses it.

No new subject or capability may be added to the dynamic routes. Security fixes
continue during coexistence. Removing a dynamic family requires tests proving
the v1 replacement, authorization denials, and absence of that legacy route.
