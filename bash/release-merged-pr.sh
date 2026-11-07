#!/usr/bin/env bash
# Checks whether a commit on main is the merge result (merge_commit_sha) of a
# pull request merged into main (see docs/release.md).
#
# Exit 0: it is a pull request's merge result.
# Exit 10: it isn't (a direct push, or an intermediate commit of a rebase
#          merge, which never gets its own test run).
# Exit 2: bad arguments.
#
# GitHub links a pushed commit to its pull request a few seconds after the
# push, so a run that starts right after a merge can get an empty answer. An
# empty answer is retried before the commit is treated as a direct push.
#
# Needs gh with GH_TOKEN and GITHUB_REPOSITORY (set in GitHub Actions).

set -euo pipefail

SKIP=10
ATTEMPTS=${RELEASE_MERGED_PR_ATTEMPTS:-9}
INTERVAL=${RELEASE_MERGED_PR_INTERVAL:-10}

if [[ $# -ne 1 || -z "${GITHUB_REPOSITORY:-}" ]]; then
    echo "Usage: GITHUB_REPOSITORY=owner/repo $0 <sha>" >&2
    exit 2
fi

sha=$1
if [[ ! "$sha" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Expected a full commit SHA, got '$sha'." >&2
    exit 2
fi

for ((attempt = 1; attempt <= ATTEMPTS; attempt++)); do
    # Only the PR's merge result is pushed to main as a tip and tested. The
    # other commits of a multi-commit rebase merge belong to the PR but aren't.
    merged=$(gh api "repos/$GITHUB_REPOSITORY/commits/$sha/pulls" \
        --jq "[.[] | select(.merged_at != null and .base.ref == \"main\" and .merge_commit_sha == \"$sha\")] | length")
    if [[ "$merged" -gt 0 ]]; then
        echo "$sha is the merge result of a pull request into main."
        exit 0
    fi
    if (( attempt < ATTEMPTS )); then
        echo "No merged pull request found for $sha yet; retrying ($attempt/$ATTEMPTS)."
        sleep "$INTERVAL"
    fi
done

echo "$sha is not the merge result of a pull request into main."
exit "$SKIP"
