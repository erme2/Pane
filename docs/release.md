# Release Policy

Pane and Latte release independently, but the first public preview is a
coordinated pair. Release notes must always state which Pane version is
compatible with which Latte version.

## Version Channels

Use SemVer with pre-release labels until the first stable release:

| Channel | Example | Meaning |
| --- | --- | --- |
| Alpha | `0.1.0-alpha.1` | Internal or unsafe public preview. APIs, deployment shape, and operational runbooks may still change. |
| Beta | `0.1.0-beta.1` | End-to-end flow works and compatibility is expected, but external validation is still required. |
| Release candidate | `0.1.0-rc.1` | Expected to become stable unless release-blocking defects are found. |
| Stable | `0.1.0` | Supported first stable release for the documented Pane and Latte pair. |

Increment the numeric suffix within the same channel for rapid follow-up
releases, for example `0.1.0-alpha.2`. Move to the next channel only when the
release gate for that channel passes.

## Tag Naming

Each repository owns its own tags:

- Pane tags use `v<version>`, for example `v0.1.0-alpha.1`.
- Latte tags use `v<version>`, for example `v0.1.0-alpha.1`.

Do not use one repository's tag as proof that the other repository was released.
The compatible pair must be recorded in release notes.

If a top-level coordinated artifact is needed later, name it with the release
prefix and the shared version, for example `release-0.1.0-alpha.1`.

## Release Metadata

Pane exposes non-secret build metadata at `GET /api/v1/release`. Deployment
tooling should derive version/ref/commit from GitHub release context, using
`GITHUB_REF_NAME`, `GITHUB_SHA`, and `GITHUB_RUN_NUMBER`. Automatic `main`
deployments use `0.1.0-alpha.<GITHUB_RUN_NUMBER>` as the release version.
Manual dispatches may still pass an explicit release version, typically a
tagged version such as `0.1.0-alpha.1`. Deployment then runs
`php artisan release:cache --release-version="$release_version" --ref="$GITHUB_REF_NAME" --commit="$GITHUB_SHA" --built-at="<timestamp>"`
before shipping the release artifact. The command writes
`bootstrap/cache/pane-release.php`, which is ignored by git and can be included
in the deployed release. Smoke checks should read this endpoint and record the
deployed version/ref/commit without logging secrets.

## Pane Hostinger Deployment

Merging to Pane `main` automatically runs the `Deploy Pane to Hostinger`
GitHub Actions workflow against the `production` environment. Automatic `main`
deployments run the database preflight and production migrations. The workflow
can still be run manually for controlled redeploys or explicit tagged release
versions.

The workflow is gated by a protected GitHub Environment, materializes the
`PANE_PRODUCTION_ENV` secret only as a temporary runner file, uploads the release to
`/home/u253124519/domains/erme2.com/public_html/pane`, and removes the
runner-side environment file at the end of the job.

The production `.env` is not part of release metadata, is not uploaded as an
artifact, and must not appear in logs or release notes. Store the canonical copy
in the password manager and update the protected GitHub Environment secret
before dispatching a release when values change.

The workflow records deployed version/ref/commit and smoke-check status in the
GitHub step summary. Rollback and backup decisions are tracked by Pane #99.

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
- Included release-gate checklist:

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
