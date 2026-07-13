# CRUD Authentication and Authorization

Pane's CRUD endpoints require an authenticated Laravel session. In the Burro and Pane setup, that session is created only after the WorkOS callback succeeds.

## How Authentication Reaches CRUD

1. Burro asks Pane for the current user with `GET /pane/auth/user`.
2. If there is no valid Pane session, Burro starts the WorkOS login flow through `GET /pane/auth/login-url`.
3. WorkOS redirects back to Burro with `code` and `state`.
4. Burro forwards those callback values to Pane with `POST /pane/auth/callback`.
5. Pane validates `state`, exchanges the WorkOS code, syncs the user, and logs the user in with Laravel Auth.
6. Later CRUD requests rely on the Pane session cookie. Pane does not call WorkOS again for each CRUD request.

If the session cookie is missing, expired, blocked by the browser, or not forwarded through Burro's `/pane` proxy, CRUD requests are unauthenticated and return `401 Unauthorized`.

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
| Authenticated non-admin | Allowed | `403 Forbidden` | `403 Forbidden` |
| Authenticated administrator | Allowed | Allowed | Allowed by the authorization layer |

Normal CRUD subjects are application tables such as `test_table`. Mutating operations are `POST`, `PUT`, and `DELETE`.

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

Pane's CRUD routes are in the `web` middleware stack, so Laravel's session and CSRF middleware apply to browser-backed requests. For mutating JSON requests, Burro must send the Pane session cookie and echo the encrypted `XSRF-TOKEN` cookie value in the `X-XSRF-TOKEN` header. Pane validates that header against the Laravel session token before the CRUD story runs.

`POST /auth/callback` is exempt from CSRF because it completes the external WorkOS callback flow before Burro has an authenticated Pane session. Pane still validates the WorkOS OAuth `state` value on that route.

## Troubleshooting

- `401 Unauthorized`: the browser does not have a valid Pane session. Start a fresh login from Burro and check that the Pane session cookie is being sent on `/pane/crud/*` requests.
- `403 Forbidden`: the session is valid, but the user is not authorized for that CRUD subject or mutation.
- `400 Invalid WorkOS state`: the WorkOS callback did not match Pane's stored OAuth state. See [WorkOS and Burro Authentication](workos-burro-auth.md).
