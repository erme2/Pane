# Pane

Pane is a simple, lightweight, and easy Restful API framework based on [Laravel](https://laravel.com/).

Pane (Bread) is designed to be used with [Burro](https://github.com/erme2/Burro) (Butter).

Pane and Burro together should be like a "bread and butter".

1. keep it simple and lightweight
2. testable
3. easy to use

## WorkOS Auth

Set these values in your environment:

```dotenv
WORKOS_API_KEY=sk_test_...
WORKOS_CLIENT_ID=client_...
WORKOS_REDIRECT_URI=http://localhost/auth/callback
WORKOS_RETURN_TO=http://localhost
WORKOS_PROVIDER=authkit
```

Add `WORKOS_REDIRECT_URI` to the Redirects tab for your WorkOS application.

Routes:

1. `GET /auth/login` redirects to WorkOS AuthKit.
2. `GET /auth/callback` handles the WorkOS callback and logs in the Laravel user.
3. `POST /auth/logout` clears the Laravel session and redirects to WorkOS logout.
4. `GET /auth/user` returns the current authenticated user.
