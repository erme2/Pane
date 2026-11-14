# Rollback, Backup and Operations Runbook

What to do before a release that changes the database, how to go back when a
release is bad, and what to do when a secret leaks. It covers every Pane and
Latte installation on Hostinger: `pane.erme2.com` (still deployed by Pane's own
`Deploy Pane to Hostinger` workflow, see [Hostinger](hostinger.md)) and the
installations deployed by erme2/deploy (`Deploy Pane`, `Deploy Latte`).

None of the steps below may print or paste secret values. Record versions,
commits and pass/fail results, never `.env` contents.

## Where Releases Live

- **Pane:** every merge to `main` gets an annotated `v<version>` tag
  ([Release Policy](release.md)). The tag is the release artifact: deploys
  build from the tagged source, and nothing else is stored.
- **Latte:** the same, with Latte's own `v<version>` tags.
- **Hostinger keeps no previous release.** Each deploy replaces the tree in
  place (`rsync --delete`). Pane deploys keep only `.env` and `storage/`
  (erme2/deploy also keeps `.well-known/` and its `.erme2-deploy` marker);
  Latte deploys keep only `.well-known/` and `.erme2-deploy`. Going back means
  deploying an older tag again.
- **What is live:** `GET /api/v1/release` on Pane and `/latte-release.json` on
  Latte report the deployed version, ref and commit. Each deploy run's summary
  records the same.

## Backup Checkpoint Before Migrations

Before any deploy that runs migrations, take a database backup and note it in
the release notes ("Backups confirmed").

1. Check what the release migrates: list `database/migrations` changes between
   the live version and the new tag, e.g.
   `git diff --stat v0.1.5 v0.1.6 -- database/migrations`. No changes means no
   checkpoint is needed.
2. Export the installation's database from hPanel (Databases → phpMyAdmin →
   Export, or the plan's backup tool) and keep the file outside `public_html`
   and outside every repository. Name it after the installation and the live
   version, for example `sweegly-pane-dev-v0.1.5-<date>.sql`.
3. Deploy the new tag with migrations on.

Automatic deploys of `pane.erme2.com` after each merge to `main` run
migrations without a manual checkpoint. For a merge that adds migrations, take
the backup before merging, or rely on the plan's daily backup and accept losing
the writes since it was taken.

## Migration Rollback Policy

Production migrations are forward-only.

- Never run `php artisan migrate:rollback`, `migrate:reset`, `migrate:fresh` or
  `db:wipe` against a production or shared installation. `down()` methods exist
  for local development and tests; they drop columns and tables and lose data.
- A migration must keep the previous release working: add new tables and
  nullable or defaulted columns first, and remove or rename old ones only in a
  later release once nothing reads them. That keeps an application rollback
  possible without touching the database.
- A bad migration is fixed forward with a new migration in a new release.
- Restore the backup only when data is damaged and a fix-forward migration
  cannot repair it. Writes made after the backup are lost, so record the
  window in the incident notes.

### Restoring a Backup

1. Roll the application back to the tag the backup was taken on (see below),
   with migrations off, so the code matches the restored schema.
2. In hPanel, import the backup file into the installation's database
   (Databases → phpMyAdmin → Import, or the plan's restore tool). Restore only
   that installation's database; installations sharing a database are
   separated by `APP_TABLE_PREFIX`, so check the dump holds only its tables.
3. Run the smoke checks and record the backup used and the lost-writes window.

`storage/` (uploaded files, logs) is not part of the database backup and is
not touched by deploys or by a restore.

## Rolling Back the Application

Deploy the last good tag again, with migrations off.

**Installations deployed by erme2/deploy** (e.g. `sweegly-pane-dev`,
`sweegly-latte-dev`): Actions → **Deploy Pane** (or **Deploy Latte**) → Run
workflow, choose the same `project` and `environment`, set `ref` to the last
good tag (for example `v0.1.5`) and, for Pane, turn `run_migrations` off.

**`pane.erme2.com`:** Actions in erme2/Pane → **Deploy Pane to Hostinger** →
Run workflow, select the last good tag under "Use workflow from", keep the
`production` environment and leave migrations unchecked.

Then run the smoke checks below. Roll back Pane and Latte together only when
the release notes say the older pair is the compatible one.

## Smoke Checks After a Deploy or Rollback

- Pane `GET /api/v1/release` reports the expected version and commit.
- Pane `/` and `/docs/openapi.json` respond; `GET /api/v1/session` answers with
  JSON (`401` signed out).
- Pane `GET /status` (Basic auth with `PANE_STATUS_PASSWORD`) is healthy and
  `data.messages.worker` is `ok`.
- Latte `/latte-release.json` reports the expected version, and `/latte-config.json`
  points at the right Pane.
- In a browser: sign in through Latte, reload, sign out.

The deploy workflows run most of these automatically; the `/status` and
browser checks are manual.

## Leaked or Misconfigured Secrets

1. Stop the exposure: delete the message, log or comment that shows the value
   and, for a GitHub Actions log, delete the run's logs.
2. Rotate the secret at its source:
   - **Database password:** change it in hPanel.
   - **WorkOS API key or client secret:** create a new one in the WorkOS
     dashboard, then revoke the old one after the deploy.
   - **`APP_KEY`:** generate a new key and move the old one to
     `APP_PREVIOUS_KEYS`, so data encrypted with it still decrypts. Signed-in
     users must sign in again.
   - **Managed-credential keys:** follow the rotation steps in
     [Environment Configuration](environment.md): add a new key ID, rotate,
     check, then remove the leaked key.
   - **`PANE_STATUS_PASSWORD`:** set a new random value.
   - **Hostinger SSH key:** remove the public key from the Hostinger account,
     create a new key pair and add the new public key.
3. Update the canonical copy in the password manager and the GitHub
   Environment secret (`PANE_ENV` in erme2/deploy, `PANE_PRODUCTION_ENV` for
   `pane.erme2.com`, `HOSTINGER_SSH_KEY` / `PANE_HOSTINGER_SSH_KEY`), then
   redeploy the live tag without migrations.
4. Run the smoke checks and record the incident: which secret, when it was
   exposed and rotated, and where. Never the value.

A misconfigured (not leaked) `.env` is fixed the same way from step 3: correct
the Environment secret and redeploy. The preflight rejects placeholder values
and unsafe production settings before anything is uploaded.

## Hostinger Directories

Each deploy owns one directory and must not delete another's:

| Site | Directory | Deployed by |
| --- | --- | --- |
| `erme2.com` | `/home/u253124519/domains/erme2.com/public_html` | its own deploy, which excludes `/latte/` |
| `latte.erme2.com` | `/home/u253124519/domains/erme2.com/public_html/latte` | erme2/deploy `Deploy Latte` (`erme2-latte-prod`) |
| `pane.erme2.com` | `/home/u253124519/public_html/pane` | Pane's `Deploy Pane to Hostinger`, which accepts only this path |
| other installations | the `DEPLOY_PATH` of their GitHub Environment | erme2/deploy |

- Latte lives inside the erme2.com tree, so the erme2.com deploy must keep its
  `/latte/` exclusion. Removing it would delete Latte on the next erme2.com
  deploy.
- erme2/deploy uploads only into a directory that is empty (Hostinger
  placeholders only) or marked by the same installation in `.erme2-deploy`,
  and refuses anything else. Pane's own workflow accepts only its fixed path.
- No deploy deletes `.env` or `storage/`. erme2/deploy also keeps
  `.well-known/` (SSL validation); Pane's own workflow does not, so a
  `.well-known/` file placed in `/home/u253124519/public_html/pane` is removed
  by the next `pane.erme2.com` deploy.
- When adding a site under another site's directory, add an exclusion to the
  outer site's deploy first.
