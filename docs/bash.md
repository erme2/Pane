# Bash Scripts

Run all scripts from the Pane repository root. The scripts assume Laravel dependencies are installed and that an environment file for the selected environment exists.

`bash/refresh.sh` and any script that calls it also require a root `.env` file because Laravel boots from `.env` before the script sources `.env.<environment>`. For a testing-only checkout, copy `.env.testing` to `.env` first:

```bash
cp .env.testing .env
php artisan key:generate --force
```

## Script Overview

| Script | Purpose |
| --- | --- |
| `bash/clear.sh` | Clears Laravel caches and compiled framework artifacts for one environment. |
| `bash/hostinger-preflight.sh` | Checks Hostinger production runtime prerequisites without printing secret values. |
| `bash/release-tag-gate.sh` | Decides whether an earlier `main` commit gets its own release tag (gate for `release-tag.sh`). |
| `bash/release-tag.sh` | Creates and pushes the next `v<version>` release tag for a commit, and for earlier untagged merges. |
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

## `bash/hostinger-preflight.sh`

Checks the Hostinger production runtime before the first alpha release. It
verifies PHP, Composer, required PHP extensions, production environment safety
settings, writable Laravel directories, managed-credential key configuration,
database connectivity, and Composer production platform requirements.

The script reports only key names and pass/fail state. It must not print
`APP_KEY`, WorkOS secrets, database credentials, managed-credential keys, or
other secret values.

```bash
./bash/hostinger-preflight.sh [-e env-file] [-d yes|no]
```

Options:

- `-e`: environment file to inspect; defaults to `.env`.
- `-d`: run the live MySQL/MariaDB connectivity check; defaults to `yes`.

When `-e` points at a staged environment file, the script copies it to a
temporary Laravel-recognized `.env.*` file for the Laravel boot/cache check and
removes that temporary file before exit.

Use `-d no` only while preparing a host before database credentials are
available.

## `bash/release-tag.sh`

Creates the next release tag described in `docs/release.md` and pushes it. The
`Tag main release` GitHub Actions workflow runs it after every merged pull
request; run it by hand only to repair a missed tag.

```bash
./bash/release-tag.sh [-c commit] [-r remote] [-p yes|no] [-g gate-command]
```

Options:

- `-c`: commit to tag; defaults to `HEAD`.
- `-r`: remote to fetch tags from and push to; defaults to `origin`.
- `-p`: `no` prints the tags it would create, as `<tag> <commit>` lines, from
  local tags without fetching, tagging, or pushing; defaults to `yes`.
- `-g`: gate command for earlier commits, run as `<gate-command> <sha>`. Exit
  `0` tags the commit, exit `10` skips it, any other exit stops the script
  without tagging. Without a gate, every earlier untagged commit is tagged.

Before tagging the commit, the script tags, oldest first, every untagged
first-parent commit between the latest tag and that commit that the gate
accepts, so each merge gets its own version. With no tags yet, or when the
latest tag is not an ancestor of the commit, it only tags the commit itself.

The script exits without tagging when the commit already has a `v*` tag or is
older than the latest tag (versions only go up). It fails when the latest tag
is not `vX.Y.Z` or `vX.Y.Z-<label>.<n>`; push the next tag manually in that
case.

## `bash/release-tag-gate.sh`

Gate used by the `Tag main release` workflow as `release-tag.sh -g`. For an
earlier commit on `main`, it exits `0` when the commit belongs to a merged pull
request into `main` and its own `Test main / test` check passed, and `10` when
there is no merged pull request or the check did not pass. It polls while the
check is queued or running and exits `1` after the timeout.

```bash
GITHUB_REPOSITORY=erme2/Pane GH_TOKEN=... ./bash/release-tag-gate.sh <sha>
```

Environment:

- `RELEASE_TAG_CHECK_NAME`: check run to require; defaults to `Test main / test`.
- `RELEASE_TAG_GATE_TIMEOUT`: seconds to wait for a running check; defaults to `1200`.
- `RELEASE_TAG_GATE_INTERVAL`: seconds between polls; defaults to `20`.

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

Refresh the default SQLite test database with test migrations and seed data:

```bash
./bash/refresh.sh -o no -c yes -t yes -s yes
```

Run the full test suite without prompts and continue after failures:

```bash
./bash/test.sh -o no -f no
```
