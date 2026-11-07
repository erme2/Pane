#!/usr/bin/env bash
# Gate for bash/release-tag.sh -g: decides whether an earlier untagged commit
# on main gets its own release tag (see docs/release.md).
#
# Exit 0: the commit is the merge result (merge_commit_sha) of a pull request
#         merged into main, and its own "Test main" check passed, so it is
#         tagged.
# Exit 10: not a pull request's merge result (a direct push, or an
#          intermediate commit of a rebase merge, which never gets its own
#          test run), or its tests did not pass; skip it.
# Exit 1: the tests are still running after the timeout, or the GitHub API
#         failed. The tag run stops so it can be re-run.
#
# Needs gh with GH_TOKEN and GITHUB_REPOSITORY (set in GitHub Actions).

set -euo pipefail

SKIP=10
CHECK_NAME=${RELEASE_TAG_CHECK_NAME:-Test main / test}
TIMEOUT=${RELEASE_TAG_GATE_TIMEOUT:-1200}
INTERVAL=${RELEASE_TAG_GATE_INTERVAL:-20}

if [[ $# -ne 1 || -z "${GITHUB_REPOSITORY:-}" ]]; then
    echo "Usage: GITHUB_REPOSITORY=owner/repo $0 <sha>" >&2
    exit 2
fi

sha=$1
if [[ ! "$sha" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Expected a full commit SHA, got '$sha'." >&2
    exit 2
fi

status=0
"$(dirname "$0")/release-merged-pr.sh" "$sha" || status=$?
if [[ "$status" -ne 0 ]]; then
    exit "$status"
fi

deadline=$((SECONDS + TIMEOUT))
while true; do
    # Latest run of the check, as "<status> <conclusion>"; empty if none yet.
    state=$(gh api -X GET "repos/$GITHUB_REPOSITORY/commits/$sha/check-runs" \
        -f check_name="$CHECK_NAME" \
        --jq '.check_runs | sort_by(.started_at) | last | select(. != null) | "\(.status) \(.conclusion)"')

    case "$state" in
        "completed success" )
            echo "$sha passed '$CHECK_NAME'."
            exit 0;;
        completed\ * )
            echo "$sha did not pass '$CHECK_NAME' ($state)."
            exit "$SKIP";;
    esac

    if (( SECONDS >= deadline )); then
        echo "Timed out waiting for '$CHECK_NAME' on $sha (state: ${state:-none})." >&2
        exit 1
    fi
    echo "Waiting for '$CHECK_NAME' on $sha (state: ${state:-none})."
    sleep "$INTERVAL"
done
