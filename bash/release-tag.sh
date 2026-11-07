#!/usr/bin/env bash
# Creates and pushes the next Pane release tag (see docs/release.md).
#
# Pre-release tags bump the numeric suffix (v0.1.0-alpha.1 -> v0.1.0-alpha.2).
# Stable tags bump the patch (v0.1.0 -> v0.1.1). Channel or base-version
# changes are manual: push the new tag by hand and the next run continues
# from it.

set -euo pipefail

COMMIT=HEAD
REMOTE=origin
PUSH=yes
FIRST_TAG=v0.1.0-alpha.1
MAX_ATTEMPTS=5

usage() {
    echo "Usage: $0 [-c commit] [-r remote] [-p yes|no]" >&2
    exit 2
}

while getopts ":c:r:p:" opt; do
    case $opt in
        c ) COMMIT=$OPTARG;;
        r ) REMOTE=$OPTARG;;
        p ) PUSH=$OPTARG;;
        * ) usage;;
    esac
done

case "$PUSH" in
    yes|no ) ;;
    * ) usage;;
esac

sha=$(git rev-parse --verify "${COMMIT}^{commit}")

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
        # A newer commit was tagged first; its tag already contains this one.
        if git merge-base --is-ancestor "$sha" "$latest_sha"; then
            echo "Commit $sha is already included in $latest; not tagging."
            exit 0
        fi
    fi

    tag=$(next_tag "$latest")

    if [[ "$PUSH" == "no" ]]; then
        echo "$tag"
        exit 0
    fi

    git tag -a "$tag" -m "Pane $tag" "$sha"
    if git push --quiet "$REMOTE" "refs/tags/$tag"; then
        echo "Tagged $sha as $tag."
        exit 0
    fi

    # Another run pushed this tag first: drop ours and recompute.
    git tag -d "$tag" >/dev/null
    echo "Tag $tag already exists on $REMOTE; retrying ($attempt/$MAX_ATTEMPTS)." >&2
done

echo "Unable to push a release tag after $MAX_ATTEMPTS attempts." >&2
exit 1
