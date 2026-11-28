# CRUD Authentication and Authorization

Pane's CRUD endpoints require an authenticated Laravel session. In the Latte and Pane setup, that session is created only after the WorkOS callback succeeds.

## How Authentication Reaches CRUD

1. Latte asks Pane for the current user with `GET /pane/auth/user`.
2. If there is no valid Pane session, Latte starts the WorkOS login flow through `GET /pane/auth/login-url`.
3. WorkOS redirects back to Latte with `code` and `state`.
4. Latte forwards those callback values to Pane with `POST /pane/auth/callback`.
5. Pane validates `state`, exchanges the WorkOS code, syncs the user, and logs the user in with Laravel Auth.
6. Later CRUD requests rely on the Pane session cookie. Pane does not call WorkOS again for each CRUD request.

If the session cookie is missing, expired, blocked by the browser, or not forwarded through Latte's `/pane` proxy, CRUD requests are unauthenticated and return `401 Unauthorized`.

## Protected Routes

The catch-all story routes are behind Laravel's `auth` middleware:

```http
GET    /crud/{subject}
POST   /crud/{subject}
GET    /crud/{subject}/{key}
PUT    /crud/{subject}/{key}
DELETE /crud/{subject}/{key}
```

This means the CRUD story is not loaded until Laravel has identified an authenticated Pane user.

## Authorization Rules

Pane currently treats `user_type_id = 1` as the administrator user type.

| User state | Normal CRUD reads | Normal CRUD mutations | System/meta subjects |
| --- | --- | --- | --- |
| Unauthenticated | `401 Unauthorized` | `401 Unauthorized` | `401 Unauthorized` |
| Authenticated non-admin | Allowed only on a table with an access rule (own rows on a members-rule or owner-rule table); a table with no rule answers `403` | `403 Forbidden`, except on a members-rule table (own rows) | `403 Forbidden` |
| Authenticated administrator | Allowed (only own rows on an owner-rule table) | Allowed (only own rows on an owner-rule table) | Allowed by the authorization layer |

Normal CRUD subjects are application tables such as `test_table`. Mutating operations are `POST`, `PUT`, and `DELETE`.

## Pane's own row rules (Pane#232)

Pane filters every row a signed-in user reads or writes from the session, with rules that Pane owns: `config/pane_access.php`, keyed by table name, changed only by a deploy. A map cannot add, change or remove them.

- Rule kinds: `members` (the same fields as a map's `access.members`, below), `owner` (`{"key": "<field>"}`, below) and `read` (`true`: any signed-in user reads every row, only administrators write; for shared catalogues such as Sweegly's products).
- A table with no rule is refused to non-administrators, reads included: `403 Forbidden`. Administrators keep access, except to the rows an owner rule hides.
- Pane's rule for a table wins over the map's `access`. While Pane has no rule for a table, the map's `access` still counts (transition: the maps drop it in a later ticket). A table with neither is refused.
- Joined tables (`join`) are read through their own rule, so a joined table with no rule is refused as well.
- `config/pane_access.php` ships the rules of Sweegly's tables, taken from `data/maps/sweegly.json`: `categories`, `products`, `chains`, `shops`, `offers` (`read`), and `lists`, `list_members`, `list_items` (`members`). An install with other tables adds its own entries there.

## Reports and blocks (Pane#245)

Tables for reporting content and blocking members come from maps (erme2/sweegly `data/`); the rules are Pane's, in `config/pane_access.php`. The maps must use the column names the rules name.

- **Reports rule** (`content_reports`: `reports`): any signed-in user creates a row for a list they belong to, with exactly one of the `targets` set (`list_item_id` or `message_id`) and one of the `reasons` (Spam, Abusive or hateful, Sexual content, Personal information, Illegal content, Other); anything else answers `422`, a list they don't belong to `403`. Pane sets the reporter (`reporter_membership_id`) from the session and the status to `open`; the client's values are ignored. Only an administrator reads reports (everyone else gets `403`, reads included) and changes the status to `open` or `closed`: an update is a full row, but every value but the status is ignored and the stored one kept. Nobody deletes a report through the API. The note (up to 500 characters) is the map's `max` rule.
- **Blocks** (`member_blocks`: `owner` with `member_of`): the owner rule on `blocker_membership_id`, so a blocker reads, creates and deletes (unblocks) only their own rows. Pane sets the blocker from the session on a new row and ignores what the client sent for it. A new row must be for a list the blocker belongs to (`403` otherwise) and name another member in `blocked_membership_id` (`422` when it is the blocker or empty). An update that touches the list or the blocked member is held to the same two checks, so a block cannot be rewritten into one that breaks them.
- **Hiding blocked content** (`hide_blocked` inside a `members` rule): a read of a table that carries it leaves out the rows whose author column holds a member the signed-in user blocked in that row's list. Only the blocker sees the difference; writes and administrators are not filtered. The rule is not switched on for `list_items` yet: it needs an author column on that table (and on the messages table), which comes with the maps of erme2/sweegly#41.

## Members rule (one hop)

A table map can carry an `access` rule that opens the table to signed-in members (Pane#185). The rule names a members table and the columns that connect a row to it:

```json
"access": {"members": {"table": "list_members", "key": "list_id", "members_key": "list_id", "members_user": "user_uuid"}}
```

- `key` is a field of the table; `members_key` and `members_user` are fields of the members table, which must be mapped itself. `members_user` holds the user's `uuid`. Maps are read from `maps_tables` / `maps_fields` (the new layer); the legacy `map_*` tables only serve system subjects.
- A user reads, updates and deletes only the rows whose `key` value has a row in the members table with their `uuid`. A list read returns just those rows. A key the user doesn't belong to (or that doesn't exist) answers `403`.
- `POST` on a table whose `key` is its own primary key (a new list) is allowed for any signed-in user when the members table has a rule of its own: the creator's members row is added in the same transaction. The members table's other columns need defaults.
- `POST` on any other rule table (items, members) needs the user to already belong to the `key` value in the body; a `PUT` that moves a row to another key needs the same. So nobody can add themselves to someone else's list, but a member can add another user to theirs.
- Optional roles (Pane#195): add `"role": {"field": "role", "read": "read", "edit": "edit"}` inside the `members` rule. `field` is a field of the members table; `read` and `edit` are the two values it can hold. A member whose role is `read` can read the rows, in every table that carries the rule, but any write answers `403`; `edit` reads and writes; any other value, or no members row, gives nothing. The creator of a new parent row (a new list) gets the `edit` value. Without `role`, every member can read and write.
- Optional creator (Pane#227): add `"creator": "created_by_membership_id"` inside the `members` rule of the parent table, naming a field of that table. On `POST` Pane sets that field to the signed-in user's `uuid` in the same save that adds the creator's members row, so a name-only create works even when the column is `NOT NULL`. Any value the client sends for it is ignored on `POST` and on `PUT`; it never changes after create.
- Administrators keep access to everything. System subjects stay administrator-only. A rule whose members table or columns are not in the maps answers `500` (fail closed). `app:install` refuses such a rule before it writes anything.
- One hop only. Column names come from checked maps and values go through bound parameters. The CRUD route doesn't support a field whose `sql_name` differs from its `name` yet.

## Owner rule

A table map can instead carry an owner rule (Pane#218), for rows that belong to one user, such as `user_item_preferences`:

```json
"access": {"owner": {"key": "membership_id"}}
```

- `key` is a field of the table. It holds the owner's user `uuid`. A table has either a members rule or an owner rule, not both, and `owner` takes no other part.
- A user reads, updates and deletes only the rows whose `key` value is their own `uuid`. A list read returns just those rows (a `filter` cannot widen it). Another user's row, or one that doesn't exist, answers `403`.
- `POST` needs the body's `key` field to be the user's own `uuid`, and a `PUT` that sends `key` must send the user's own `uuid`; anything else answers `403`.
- Administrators get no exception: through this rule they read and write their own rows only. Removing a user's rows (account deletion, erme2/sweegly#9) is a job of its own, not a CRUD call.
- A rule whose `key` is not in the maps answers `500` (fail closed); saving a map whose `key` is not a field of the table is refused.

## System and Meta Subjects

Ordinary authenticated users cannot access these CRUD subjects:

- `tables`
- `fields`
- `field_types`
- `field_validations`
- `validation_types`
- `users`
- `user_types`

These subjects describe Pane's table metadata or authentication tables, so exposing them to ordinary users would allow them to inspect or modify the system model.

## Relationship to CSRF

Authentication answers "who is making this request". Authorization answers "what is this user allowed to do". CSRF protection is separate: it protects browser-session requests from being triggered by another site.

Pane's CRUD routes are in the `web` middleware stack, so Laravel's session and CSRF middleware apply to browser-backed requests. For mutating JSON requests, Latte must send the Pane session cookie and echo the encrypted `XSRF-TOKEN` cookie value in the `X-XSRF-TOKEN` header. Pane validates that header against the Laravel session token before the CRUD story runs.

`POST /auth/callback` is exempt from CSRF because it completes the external WorkOS callback flow before Latte has an authenticated Pane session. Pane still validates the WorkOS OAuth `state` value on that route.

## Troubleshooting

- `401 Unauthorized`: the browser does not have a valid Pane session. Start a fresh login from Latte and check that the Pane session cookie is being sent on `/pane/crud/*` requests.
- `403 Forbidden`: the session is valid, but the user is not authorized for that CRUD subject or mutation.
- `400 Invalid WorkOS state`: the WorkOS callback did not match Pane's stored OAuth state. See [WorkOS and Latte Authentication](workos-latte-auth.md).
