# Testing

Pane uses PHPUnit through Laravel's test runner. The suite is split by dependency type so failures point to application behavior instead of local machine state.

## Quick Commands

Run isolated unit tests when you only need pure PHP behavior and do not need the database:

```bash
php artisan test --env=testing --testsuite=Unit
```

Run a specific test file while iterating:

```bash
php artisan test --env=testing tests/Unit/Helpers/StringHelperTest.php
```

Run the full suite the same way CI does:

```bash
./bash/test.sh -o no -f no
```

Use `-f yes` when you want the run to stop on the first failure. Use `-r no` only when the test database has already been refreshed and you intentionally want to reuse it during local iteration.

## Suite Boundaries

- `tests/Unit` is for isolated code that does not query the database, require migrated tables, use mapper-backed models, or depend on writable Laravel storage paths.
- `tests/Feature` is for HTTP behavior and integration tests that use Laravel, the test database, mapper metadata, seeded records, sessions, middleware stacks, or CRUD routes.
- Action, mapper, model, and helper tests that rely on mapped tables live under `tests/Feature` because they need the test schema and seed data.

A unit contract test checks that database-backed dependencies do not drift back into `tests/Unit`. If a new test needs `DB::`, mapped tables, `ActionHelper`, `CoreHelper`, `MapperHelper`, `ModelHelper`, `App\Models\Field`, or an `AbstractMapper` subclass, put it in `tests/Feature`.

## Testing Environment

The default testing environment is `.env.testing`:

- `APP_KEY=` stays blank in the committed template. Generate a local key after copying it to `.env`, or let CI generate an ephemeral key during the test workflow.
- `DB_CONNECTION=sqlite` keeps tests off a live MariaDB or MySQL service.
- `DB_DATABASE=database.sqlite` points Laravel at the local SQLite test database under `database/database.sqlite`.
- `CACHE_DRIVER=array` keeps Laravel cache state in memory during testing.
- `LOG_CHANNEL=stderr` avoids writing framework logs under `storage/logs` during test runs.

`phpunit.xml` also disables PHPUnit result caching with `cacheResult="false"`, so PHPUnit does not need to write a result cache file in the repository root.

## Full Suite Lifecycle

`./bash/test.sh` wraps the normal full-suite workflow:

1. Loads the selected environment, `testing` by default.
2. Calls `./bash/refresh.sh -c yes -t yes -s yes` unless `-r no` is provided.
3. Recreates the SQLite database from `.env.testing`.
4. Runs normal migrations plus `database/migrations/test`.
5. Seeds `TestTableSeeder` when `-s yes` is active.
6. Runs `php artisan test --env=testing --testdox`.
7. Rolls back test-only migrations after a successful run unless `-u no` is provided.

If tests fail, test-only migrations are left in place so the failing database state can be inspected.

## Writing Tests

Prefer the narrowest suite that proves the behavior:

- Put pure value objects, string helpers, response formatting, and config contract checks in `tests/Unit`.
- Put request handling, middleware behavior, auth/session behavior, CRUD stories, mapper-backed actions, and seeded table behavior in `tests/Feature`.
- Keep new tests deterministic: use `.env.testing`, avoid external services by default, and prefer Laravel fakes or HTTP fakes for integrations.
- When adding a new documentation or environment contract, add a small unit test that checks the document remains linked from `README.md` and mentions the operational command or setting people need.

## Non-SQLite Runs

Use Docker or another database service only when you intentionally test a non-SQLite environment. In that case, copy the matching environment template, configure the database variables explicitly, and treat the run as an integration environment rather than the default unit path.
