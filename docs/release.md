# Release Policy

Pane and Latte release independently, but the first public preview is a
coordinated pair. Release notes must always state which Pane version is
compatible with which Latte version.

## Versions

Pane uses plain SemVer versions: `0.<minor>.<patch>`, for example `0.1.5`.
Every merge to `main` bumps the patch. Moving to a new minor version (`0.2.0`)
or to `1.0.0` is a manual decision (see below).

Until 2026-11-11 Pane used alpha pre-release tags (`v0.1.0-alpha.1` to
`v0.1.0-alpha.4`). Those tags stay as they are. An alpha tag counts as its
patch number: `v0.1.0-alpha.4` is the same release as `v0.1.4`, and the next
merge after it is `v0.1.5`.

## Tag Naming

Each repository owns its own tags:

- Pane tags use `v<version>`, for example `v0.1.5`.
- Latte tags use `v<version>`, for example `v0.1.0-alpha.1`.

Do not use one repository's tag as proof that the other repository was released.
The compatible pair must be recorded in release notes.

If a top-level coordinated artifact is needed later, name it with the release
prefix and the shared version, for example `release-0.1.5`.

## Automatic Tags

Every pull request merged into Pane `main` gets a release tag. The
`Tag main release` workflow runs on each push to `main`, skips commits that do
not belong to a merged pull request (`bash/release-merged-pr.sh`, which retries
for about 80 seconds because GitHub links a pushed commit to its pull request a
few seconds after the merge), runs the PR test suite against the merge
commit, and only then calls `bash/release-tag.sh` to tag it:

- With no tags yet, the first tag is `v0.1.0`. It goes on the oldest
  eligible merge from the rollout boundary on: the first `main` commit that
  contains `.github/workflows/tag-main.yml`. Older history is never tagged.
- Each merge bumps the patch: `v0.1.4` becomes `v0.1.5`.
- An old pre-release tag `vX.Y.Z-<label>.N` counts as `vX.Y.(Z+N)`, so
  `v0.1.0-alpha.4` is followed by `v0.1.5`.
- Tags are annotated, point at the merge commit, and are never moved or reused.
- Every merge gets its own tag, in merge order. Tag jobs are serialized, but
  GitHub can replace a queued tag job with a newer one, or a newer merge can
  finish its tests first. So before tagging its commit, a run tags any earlier
  untagged merges on `main`'s first-parent history, oldest first. The
  `bash/release-tag-gate.sh` gate checks each earlier commit: it must be a
  pull request's merge result (`merge_commit_sha`), and its own
  `Test main / test` check must have passed. The other commits of a
  multi-commit rebase merge belong to the pull request but are never tested on
  their own, so they are skipped. The gate waits up to 20 minutes for tests that are still running.
  Commits whose tests failed, and direct pushes, are skipped and never tagged.
  If the wait times out, the run fails without tagging; re-run it once the
  tests finish.
- If another run pushes the computed tag first, the script fetches tags again
  and picks the next free version.

Starting a new minor or major version stays a manual step. Tag the latest
`main` merge commit by hand, for example
`git tag -a v0.2.0 -m "Pane v0.2.0" <sha>` and
`git push origin refs/tags/v0.2.0`. Later merges continue from it
(`v0.2.1`, ...).

erme2/deploy's **Deploy Pane** workflow deploys Pane's latest tag when no `ref`
is given, so each merge produces a deployable version.

## Release Metadata

Pane exposes non-secret build metadata at `GET /api/v1/release`. Deployment
tooling should derive version/ref/commit from GitHub release context, using
`GITHUB_REF_NAME`, `GITHUB_SHA`, and `GITHUB_RUN_NUMBER`. Automatic `main`
deployments use `0.1.0-alpha.<GITHUB_RUN_NUMBER>` as the release version.
Manual dispatches may still pass an explicit release version, typically a
tagged version such as `0.1.5`. Deployment then runs
`php artisan release:cache --release-version="$release_version" --ref="$GITHUB_REF_NAME" --commit="$GITHUB_SHA" --built-at="<timestamp>"`
before shipping the release artifact. The command writes
`bootstrap/cache/pane-release.php`, which is ignored by git and can be included
in the deployed release. Smoke checks should read this endpoint and record the
deployed version/ref/commit without logging secrets.

## Pane Hostinger Deployment

Merging to Pane `main` automatically runs the cleanup workflow first. When
`Cleanup stale branches and pull requests` succeeds, GitHub Actions runs
`Deploy Pane to Hostinger` against the `production` environment. Automatic
deployments run the database preflight and production migrations. The workflow
can still be run manually for controlled redeploys or explicit tagged release
versions.

The workflow is gated by a protected GitHub Environment, materializes the
`PANE_PRODUCTION_ENV` secret only as a temporary runner file, uploads the release to
`/home/u253124519/public_html/pane`, and removes the
runner-side environment file at the end of the job.

The production `.env` is not part of release metadata, is not uploaded as an
artifact, and must not appear in logs or release notes. Store the canonical copy
in the password manager and update the protected GitHub Environment secret
before dispatching a release when values change.

The workflow records deployed version/ref/commit and smoke-check status in the
GitHub step summary. Rollback and backup decisions are tracked by Pane #99.

## Release Gate

A coordinated Pane + Latte release is ready only when every item of the
[Cross-App Release Gate](release-gate.md) passes. The gate also lists the
required production environment for Pane and Latte.

## First Alpha Release Notes Template

Use this template for the first alpha release notes and adapt it for later
channels:

```markdown
# Pane v0.1.0-alpha.1

Compatible release pair: Pane v0.1.0-alpha.1 + Latte v0.1.0-alpha.1

Channel: alpha
Stability: unsafe public preview; APIs and deployment workflow may change.

## Scope

- Pane deployment target:
- Latte deployment target:
- Release gate (docs/release-gate.md): copy the checklist here; ready only when every item is checked

## Validation

- Pane tests:
- Pane static analysis:
- Pane dependency and security audit:
- Latte tests:
- Latte build:
- Latte dependency and security audit:
- Cross-app smoke checks:

## Operational Notes

- Required production secrets were generated or rotated:
- Database migrations reviewed:
- Backups confirmed:
- Rollback path confirmed:
```

The release note must not contain secret values, host credentials, database
passwords, API keys, or private key material.
