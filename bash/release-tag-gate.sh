#!/usr/bin/env bash
# Gate for bash/release-tag.sh -g: decides whether an earlier untagged commit
# on main gets its own release tag (see docs/release.md).
#
# Exit 0: the commit is a merged pull request into main and its own
#         "Test main" check passed, so it is tagged.
# Exit 10: not a merged pull request, or its tests did not pass; skip it.
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

merged=$(gh api "repos/$GITHUB_REPOSITORY/commits/$sha/pulls" \
    --jq '[.[] | select(.merged_at != null and .base.ref == "main")] | length')
if [[ "$merged" -eq 0 ]]; then
    echo "$sha has no merged pull request into main."
    exit "$SKIP"
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
