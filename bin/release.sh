#!/usr/bin/env bash
# One-command release: bump both version declarations, commit, tag, push.
# GitHub Actions then builds the vendor-complete dist zip and attaches it to
# the generated GitHub Release — nothing else to do.
#
# The tag is pushed from here (not by CI) on purpose: tags pushed with a
# workflow's GITHUB_TOKEN do not trigger other workflows, so CI-side tagging
# would silently orphan the release build.
#
# Usage: bash bin/release.sh 1.2.0
set -euo pipefail

version="${1:?usage: bash bin/release.sh <X.Y.Z>}"

if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
	echo "invalid version '$version' — expected X.Y.Z" >&2
	exit 1
fi

if [[ -n "$( git status --porcelain )" ]]; then
	echo "working tree not clean — commit or stash first" >&2
	exit 1
fi

if git rev-parse -q --verify "refs/tags/v$version" >/dev/null; then
	echo "tag v$version already exists" >&2
	exit 1
fi

here="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
cd "$here"

git pull --ff-only

php bin/bump-version.php "$version"

git add wp-sentry-logger.php
git commit -m "Release v$version"
git tag -a "v$version" -m "WP Sentry Logger $version"
git push
git push origin "v$version"

echo
echo "v$version pushed — CI is building the dist zip now:"
echo "  https://github.com/lirrensi/wp-sentry-logger/releases/tag/v$version"
