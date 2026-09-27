#!/usr/bin/env bash
#
# Checks that a tag may be released, before anything is built:
#   - the tag is v<major>.<minor>.<patch>;
#   - the plugin header Version: and MAC_MEMBERS_VERSION both equal the tag without the v;
#   - the commit is on origin/main.
#
# Usage: .github/scripts/check-release.sh <tag> [<commit>]
# <commit> defaults to HEAD. When running it locally, run `git fetch origin` first.

set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
	echo "Usage: $0 <tag> [<commit>]" >&2
	exit 2
fi

fail() {
	echo "Release check failed: $*" >&2
	exit 1
}

tag="$1"
main_ref='refs/remotes/origin/main'
tag_pattern='^v([0-9]+\.[0-9]+\.[0-9]+)$'

commit="$(git rev-parse --verify --quiet "${2:-HEAD}^{commit}")" || fail "'${2:-HEAD}' is not a commit."

[[ "$tag" =~ $tag_pattern ]] || fail "tag '$tag' is not v<major>.<minor>.<patch>, for example v1.2.3."
version="${BASH_REMATCH[1]}"

header_version="$(git show "$commit:mac-members.php" | sed -n 's|^[[:space:]/*#@]*Version:[[:space:]]*||p' | sed 's|[[:space:]]*$||')"
constant_version="$(git show "$commit:inc/constants.php" | sed -n "s|^[[:space:]]*define([[:space:]]*'MAC_MEMBERS_VERSION',[[:space:]]*'\([^']*\)'[[:space:]]*);.*|\1|p")"

[[ "$header_version" == "$version" ]] || fail "$tag needs 'Version: $version' in mac-members.php, found '$header_version'."
[[ "$constant_version" == "$version" ]] || fail "$tag needs MAC_MEMBERS_VERSION '$version' in inc/constants.php, found '$constant_version'."

git rev-parse --verify --quiet "$main_ref" >/dev/null || fail "origin/main is missing. Fetch it first."
git merge-base --is-ancestor "$commit" "$main_ref" || fail "commit $commit is not on origin/main."

echo "Release check passed: $tag matches version $version, and commit $commit is on origin/main."
