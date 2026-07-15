# Testing

Pane uses PHPUnit through Laravel's test runner. The suite is split by dependency type so failures point to application behavior instead of local machine state.

## Suite Boundaries

- `tests/Unit` is for isolated code that does not query the database, require migrated tables, or depend on writable Laravel storage paths.
- `tests/Feature` is for HTTP behavior and integration tests that use Laravel, the test database, mapper metadata, seeded records, sessions, or middleware stacks.
- Action, mapper, model, and helper tests that rely on mapped tables live under `tests/Feature` because they need the test schema and seed data.

A unit contract test checks that database-backed dependencies do not drift back into `tests/Unit`.

## Testing Environment

The default testing environment is `.env.testing`:

- `DB_CONNECTION=sqlite` keeps tests off a live MariaDB or MySQL service.
- `DB_DATABASE=database.sqlite` points Laravel at the local SQLite test database.
- `CACHE_DRIVER=array` keeps Laravel cache state in memory during testing.
- `LOG_CHANNEL=stderr` avoids writing framework logs under `storage/logs` during test runs.

`phpunit.xml` also disables PHPUnit result caching with `cacheResult="false"`, so PHPUnit does not need to write a result cache file in the repository root.

## Running Tests

Run the hermetic unit suite without refreshing the database:

```bash
php artisan test --env=testing --testsuite=Unit
```

Run the full suite with the repository helper, which refreshes the SQLite database, runs normal migrations plus `database/migrations/test`, seeds test records, and rolls back test-only migrations after a successful run:

```bash
./bash/test.sh -o no -f no
```

Use Docker or another database service only when you intentionally test a non-SQLite environment. In that case, copy the matching environment template, configure the database variables explicitly, and treat the run as an integration environment rather than the default unit path.
