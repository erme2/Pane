# WorkOS and Burro Authentication

Pane is the server-side owner of WorkOS authentication. Burro owns the browser experience and forwards WorkOS callback data to Pane, but Pane is responsible for validating OAuth state, exchanging authorization codes, syncing users, and creating Laravel sessions.

## Components

- **Burro** is the React browser app. It checks the current session, redirects the browser to WorkOS AuthKit, receives the callback route in the browser, and forwards callback parameters to Pane through its `/pane` proxy.
- **Pane** is the Laravel backend. It generates the WorkOS authorization URL, stores OAuth state, validates callbacks, exchanges codes with WorkOS, syncs the local user, and creates the authenticated Laravel session.
- **WorkOS AuthKit** is the external identity provider. It authenticates the user and redirects the browser back to Burro with `code` and `state` query parameters.

## Required Pane Configuration

Pane reads WorkOS settings from `config/services.php`:

```dotenv
WORKOS_API_KEY=sk_test_...
WORKOS_CLIENT_ID=client_...
WORKOS_REDIRECT_URI=https://burro.localhost/auth/callback
WORKOS_RETURN_TO=https://burro.localhost
WORKOS_PROVIDER=authkit
WORKOS_ORGANIZATION_ID=
WORKOS_CONNECTION_ID=
```

`WORKOS_API_KEY`, `WORKOS_CLIENT_ID`, and `WORKOS_REDIRECT_URI` are required before Pane can generate a login URL. `WORKOS_RETURN_TO` controls the fallback return URL and the allowed redirect origin for Burro. `WORKOS_PROVIDER` defaults to `authkit`; if it is blank, Pane can use `WORKOS_ORGANIZATION_ID` or `WORKOS_CONNECTION_ID` instead.

The WorkOS application must include `WORKOS_REDIRECT_URI` in its allowed redirect URLs. For the Docker/local HTTPS setup, that URI should point to Burro, not Pane, because Burro receives the browser callback first.

## Browser Login Flow

1. Burro checks whether the browser already has a Pane session:

   ```http
   GET /pane/auth/user
   ```

   The `/pane` prefix is Burro's Vite proxy. Pane receives this as `GET /auth/user`.

2. If Pane returns `401` or `403`, Burro asks Pane for a login URL:

   ```http
   GET /pane/auth/login-url?redirect_to=https://burro.localhost/dashboard
   ```

3. Pane generates a random OAuth `state` with `WorkOsService::makeState()`, stores it in the Laravel session as `workos_state`, stores the intended return URL as `workos_intended_url`, and returns:

   ```json
   {
     "authorization_url": "https://api.workos.com/user_management/authorize?...",
     "state": "..."
   }
   ```

   Pane also sets a short-lived HTTP-only `pane_workos_state` cookie. This gives Pane a second server-owned value to validate JSON callback requests when the normal Laravel session value is not available as expected.

4. Burro redirects the browser to `authorization_url`.

5. WorkOS authenticates the user and redirects back to Burro:

   ```text
   https://burro.localhost/auth/callback?code=...&state=...
   ```

6. Burro forwards the callback parameters to Pane:

   ```http
   POST /pane/auth/callback
   Content-Type: application/json

   {
     "code": "...",
     "state": "..."
   }
   ```

7. Pane validates that the returned `state` matches either the Laravel session `workos_state` or the `pane_workos_state` cookie. If neither value matches, Pane returns:

   ```json
   {
     "message": "Invalid WorkOS state."
   }
   ```

8. If state is valid, Pane exchanges the one-time WorkOS `code` with `/user_management/authenticate`.

9. Pane syncs the WorkOS user into the local `users` table, logs the user in with Laravel Auth, regenerates the session, clears `workos_state`, stores WorkOS session metadata, and forgets the `pane_workos_state` cookie.

10. Pane returns the authenticated user and organization ID to Burro. Burro stores that small user snapshot in browser `sessionStorage`; it does not receive WorkOS access or refresh tokens.

## Ownership Rules

- Pane owns OAuth state generation and validation.
- Pane owns WorkOS code exchange and token handling.
- Pane owns the authenticated Laravel session.
- Burro owns browser redirects and callback forwarding.
- Burro must forward the WorkOS callback `state` unchanged to Pane.
- Burro should not create its own independent OAuth state contract unless Pane stops owning state validation.

## Local Docker Expectations

In the local HTTPS Docker setup:

- Burro is loaded at `https://burro.localhost`.
- Pane is loaded at `https://pane.localhost`.
- Burro proxies API calls from `/pane/*` to Pane's Nginx service on the Docker network.
- Pane should use `WORKOS_REDIRECT_URI=https://burro.localhost/auth/callback`.
- Pane should use `WORKOS_RETURN_TO=https://burro.localhost` or another allowed Burro origin.

Burro's Docker env normally contains:

```dotenv
VITE_PANE_BASE_URL=/pane
VITE_PANE_PROXY_TARGET=https://nginx
VITE_PANE_PROXY_HOST=pane.localhost
```

Pane's local HTTPS certificates and host entries are documented in the README local development section.

## Troubleshooting Invalid WorkOS State

`Invalid WorkOS state.` means Pane received a callback whose `state` did not match the server-owned value from the login-url request.

Common causes:

- Reusing an old callback URL after a failed or completed login attempt.
- Starting login in one browser/session and completing it in another.
- Clearing cookies or session storage between `/auth/login-url` and `/auth/callback`.
- Calling Pane's callback directly instead of letting Burro receive the WorkOS browser redirect and forward it through `/pane/auth/callback`.
- Misconfigured local domains, proxy target, or cookie/session settings that prevent Pane's session or `pane_workos_state` cookie from returning to Pane.

The normal recovery is to clear the browser session for `burro.localhost` and `pane.localhost`, then begin a fresh login from Burro.
