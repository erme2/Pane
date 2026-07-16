# Bash Scripts

Run all scripts from the Pane repository root. The scripts assume Laravel dependencies are installed and that an environment file for the selected environment exists.

`bash/refresh.sh` and any script that calls it also require a root `.env` file because Laravel boots from `.env` before the script sources `.env.<environment>`. For a testing-only checkout, copy `.env.testing` to `.env` first:

```bash
cp .env.testing .env
```

## Script Overview

| Script | Purpose |
| --- | --- |
| `bash/clear.sh` | Clears Laravel caches and compiled framework artifacts for one environment. |
| `bash/generate-certs.sh` | Creates locally trusted HTTPS certificates for Pane and Burro development hosts. |
| `bash/refresh.sh` | Rebuilds the configured database, runs migrations, and optionally loads test schema and seed data. |
| `bash/test.sh` | Runs the Laravel test suite, optionally refreshing the database before the run. |

## `bash/clear.sh`

Clears Laravel's compiled files, application cache, configuration cache, event cache, route cache, scheduler mutex cache, compiled views, and any PHP files left under `bootstrap/cache`.

```bash
./bash/clear.sh [-f environment]
```

Options:

- `-f`: Laravel environment name; defaults to `testing`.

Use this when cached configuration, routes, or views are stale during local development or testing.

## `bash/generate-certs.sh`

Installs the local `mkcert` certificate authority when necessary and generates a shared local development certificate for Pane and Burro.

```bash
./bash/generate-certs.sh
```

Requirements:

- `mkcert` must be installed.
- Firefox users should also install `nss` so Firefox trusts the local certificate authority.

Outputs:

- `nginx/certs/localhost.pem`
- `nginx/certs/localhost-key.pem`

The generated certificate covers `pane.localhost`, `burro.localhost`, `localhost`, `127.0.0.1`, and `::1`. The `nginx/certs` directory is ignored by Git because it contains local private key material.

## `bash/refresh.sh`

Rebuilds the configured database for one Laravel environment. By default it loads `.env.testing`, asks for confirmation, deletes and recreates the database, runs normal migrations, and does not run test-only migrations or seed data unless requested.

The script exits with `Error: .env file not found.` when the root `.env` file is missing, even when the selected `.env.<environment>` file exists.

This script is destructive when database deletion is enabled.

```bash
./bash/refresh.sh [-c yes|no] [-d yes|no] [-f environment] \
  [-o yes|no] [-s yes|no] [-t yes|no] [-v yes|no]
```

Options:

- `-c`: clear Laravel caches before migration; defaults to `no`.
- `-d`: delete and recreate the configured database; defaults to `yes`.
- `-f`: environment name, loaded from `.env.<environment>`; defaults to `testing`.
- `-o`: show the confirmation prompt; defaults to `yes`.
- `-s`: run `TestTableSeeder`; defaults to `no`.
- `-t`: run migrations under `database/migrations/test`; defaults to `no`.
- `-v`: print progress and selected options; defaults to `yes`.

Supported database reset paths:

- `sqlite`: recreates the SQLite file named by `DB_DATABASE`.
- `mariadb`: drops and recreates `DB_DATABASE` through the `mariadb` client.
- `mysql`: drops and recreates `DB_DATABASE` through the `mysql` client.

## `bash/test.sh`

Runs the Laravel test suite through `php artisan test`. By default it refreshes the testing database first, runs test migrations, seeds test records, and rolls back test-only migrations after a successful run.

Because the default `-r yes` path calls `bash/refresh.sh`, the default test command also requires the root `.env` file. Use `-r no` only when the database has already been refreshed and you do not want `bash/test.sh` to call `bash/refresh.sh`.

```bash
./bash/test.sh [-c environment] [-f yes|no] [-o yes|no] \
  [-r yes|no] [-s yes|no] [-u yes|no] [-v yes|no]
```

Options:

- `-c`: environment name; defaults to `testing`.
- `-f`: stop on the first test failure; defaults to `yes`.
- `-o`: show confirmation prompts; defaults to `yes`.
- `-r`: refresh the database before testing; defaults to `yes`.
- `-s`: run `TestTableSeeder` during refresh; defaults to `yes`.
- `-u`: roll back test-only migrations after a successful run; defaults to `yes`.
- `-v`: print progress and selected options; defaults to `yes`.

The CI-friendly full-suite command is:

```bash
./bash/test.sh -o no -f no
```

Use `-r no` only when the database has already been refreshed and you intentionally want to reuse it while iterating locally without invoking `bash/refresh.sh`.

## Common Workflows

Regenerate local HTTPS certificates:

```bash
./bash/generate-certs.sh
```

Refresh the default SQLite test database with test migrations and seed data:

```bash
./bash/refresh.sh -o no -c yes -t yes -s yes
```

Run the full test suite without prompts and continue after failures:

```bash
./bash/test.sh -o no -f no
```
