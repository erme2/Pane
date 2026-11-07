#!/usr/bin/env bash
# Creates and pushes the next Pane release tag (see docs/release.md).
#
# Pre-release tags bump the numeric suffix (v0.1.0-alpha.1 -> v0.1.0-alpha.2).
# Stable tags bump the patch (v0.1.0 -> v0.1.1). Channel or base-version
# changes are manual: push the new tag by hand and the next run continues
# from it.
#
# Every merge gets its own tag. Before tagging the requested commit, the
# script tags, oldest first, any untagged first-parent commits between the
# latest tag and that commit, so a merge whose tag job was skipped or ran late
# still gets its own, lower version. The optional gate command decides which
# of those earlier commits qualify (see usage below).

set -euo pipefail

COMMIT=HEAD
REMOTE=origin
PUSH=yes
GATE=
FIRST_TAG=v0.1.0-alpha.1
MAX_ATTEMPTS=5
GATE_SKIP=10

usage() {
    echo "Usage: $0 [-c commit] [-r remote] [-p yes|no] [-g gate-command]" >&2
    echo "  The gate is run as '<gate-command> <sha>' for each earlier untagged" >&2
    echo "  commit: exit 0 tags it, exit $GATE_SKIP skips it, anything else stops." >&2
    exit 2
}

while getopts ":c:r:p:g:" opt; do
    case $opt in
        c ) COMMIT=$OPTARG;;
        r ) REMOTE=$OPTARG;;
        p ) PUSH=$OPTARG;;
        g ) GATE=$OPTARG;;
        * ) usage;;
    esac
done

case "$PUSH" in
    yes|no ) ;;
    * ) usage;;
esac

sha=$(git rev-parse --verify "${COMMIT}^{commit}")

# Gate results already known in this run, as "<sha> <tag|skip>" lines, so a
# retry after a push race doesn't ask the gate again.
gate_cache=""

# Highest v* tag by version order; versionsort.suffix sorts pre-releases
# before their stable release.
latest_tag() {
    git -c versionsort.suffix=- tag --list 'v[0-9]*' --sort=-v:refname | head -n 1
}

next_tag() {
    local latest=$1
    if [[ -z "$latest" ]]; then
        echo "$FIRST_TAG"
    elif [[ "$latest" =~ ^v([0-9]+\.[0-9]+\.[0-9]+)-([0-9A-Za-z-]+)\.([0-9]+)$ ]]; then
        echo "v${BASH_REMATCH[1]}-${BASH_REMATCH[2]}.$((BASH_REMATCH[3] + 1))"
    elif [[ "$latest" =~ ^v([0-9]+)\.([0-9]+)\.([0-9]+)$ ]]; then
        echo "v${BASH_REMATCH[1]}.${BASH_REMATCH[2]}.$((BASH_REMATCH[3] + 1))"
    else
        echo "Unsupported latest tag '$latest'; push the next tag manually." >&2
        exit 1
    fi
}

fetch_tags() {
    if [[ "$PUSH" == "yes" ]]; then
        git fetch --quiet --tags "$REMOTE"
    fi
}

is_tagged() {
    [[ -n "$(git tag --points-at "$1" --list 'v[0-9]*' | head -n 1)" ]]
}

# Sets DECISION to "tag" or "skip" for an earlier commit; exits when the
# gate fails. Runs in the main shell so the cache and exit both take effect.
gate_decision() {
    local commit=$1 status=0
    DECISION=$(printf '%s' "$gate_cache" | awk -v c="$commit" '$1 == c { print $2 }')
    if [[ -n "$DECISION" ]]; then
        return
    fi
    if [[ -z "$GATE" ]]; then
        DECISION=tag
        return
    fi
    $GATE "$commit" >&2 || status=$?
    if [[ "$status" -eq 0 ]]; then
        DECISION=tag
    elif [[ "$status" -eq "$GATE_SKIP" ]]; then
        DECISION=skip
    else
        echo "Gate failed for $commit (exit $status); not tagging." >&2
        exit 1
    fi
    gate_cache+="$commit $DECISION"$'\n'
}

# Sets PENDING to the untagged commits to tag, oldest first, ending with the
# requested commit.
pending_commits() {
    local latest=$1 latest_sha commit
    PENDING=()
    if [[ -n "$latest" ]]; then
        latest_sha=$(git rev-parse "${latest}^{commit}")
        if git merge-base --is-ancestor "$latest_sha" "$sha"; then
            for commit in $(git rev-list --first-parent --reverse "${latest_sha}..${sha}"); do
                if [[ "$commit" == "$sha" ]] || is_tagged "$commit"; then
                    continue
                fi
                gate_decision "$commit"
                if [[ "$DECISION" == "tag" ]]; then
                    PENDING+=("$commit")
                else
                    echo "Skipping $commit: the gate rejected it." >&2
                fi
            done
        fi
    fi
    PENDING+=("$sha")
}

for ((attempt = 1; attempt <= MAX_ATTEMPTS; attempt++)); do
    fetch_tags

    existing=$(git tag --points-at "$sha" --list 'v[0-9]*' | head -n 1)
    if [[ -n "$existing" ]]; then
        echo "Commit $sha is already tagged $existing."
        exit 0
    fi

    latest=$(latest_tag)
    if [[ -n "$latest" ]]; then
        latest_sha=$(git rev-parse "${latest}^{commit}")
        # Versions only go up, so a commit older than the latest tag can't get
        # its own tag any more. The run that created that tag either tagged it
        # or its gate rejected it.
        if git merge-base --is-ancestor "$sha" "$latest_sha"; then
            echo "Commit $sha is older than $latest and untagged; not tagging." >&2
            exit 0
        fi
    fi

    pending_commits "$latest"
    raced=no
    for commit in "${PENDING[@]}"; do
        tag=$(next_tag "$latest")

        if [[ "$PUSH" == "no" ]]; then
            echo "$tag $commit"
            latest=$tag
            continue
        fi

        git tag -a "$tag" -m "Pane $tag" "$commit"
        if git push --quiet "$REMOTE" "refs/tags/$tag"; then
            echo "Tagged $commit as $tag."
            latest=$tag
            continue
        fi

        # Another run pushed this tag first: drop ours and recompute.
        git tag -d "$tag" >/dev/null
        echo "Tag $tag already exists on $REMOTE; retrying ($attempt/$MAX_ATTEMPTS)." >&2
        raced=yes
        break
    done

    if [[ "$raced" == "no" ]]; then
        exit 0
    fi
done

echo "Unable to push a release tag after $MAX_ATTEMPTS attempts." >&2
exit 1
