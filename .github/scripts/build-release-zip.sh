#!/usr/bin/env bash
#
# Builds dist/mac-members-<tag>.zip and dist/mac-members-<tag>.zip.sha256 from a commit.
#
# The ZIP is made with git archive, so the export-ignore entries in .gitattributes are the
# only exclude list. The build fails if the ZIP would contain a path that is not listed in
# shipped_paths below.
#
# Usage: .github/scripts/build-release-zip.sh <tag> [<commit>]
# <commit> defaults to HEAD.

set -euo pipefail

if [[ $# -lt 1 || $# -gt 2 ]]; then
	echo "Usage: $0 <tag> [<commit>]" >&2
	exit 2
fi

fail() {
	echo "Build failed: $*" >&2
	exit 1
}

tag="$1"
slug='mac-members'
zip_name="$slug-$tag.zip"

# Paths that may ship, relative to the plugin folder. Directories end with /, and * also
# matches /. bin/ ships as an empty folder because its only file is export-ignored.
shipped_paths=(
	''
	'LICENSE'
	'index.php'
	"$slug.php"
	'assets/' 'assets/index.php' 'assets/*.css' 'assets/*.js'
	'bin/'
	'inc/' 'inc/*/' 'inc/*.php'
	'inc/Vendor/SureCart/Licensing/README.md'
	'release.json'
	'src/' 'src/*/' 'src/*.php'
)

cd "$(git rev-parse --show-toplevel)"
commit="$(git rev-parse --verify --quiet "${2:-HEAD}^{commit}")" || fail "'${2:-HEAD}' is not a commit."

mkdir -p dist
git archive --format=zip --prefix="$slug/" --output="dist/$zip_name" "$commit"

entries="$(unzip -Z1 "dist/$zip_name")"
unexpected=()
while IFS= read -r entry; do
	path="${entry#"$slug/"}"
	shipped=false
	if [[ "$entry" == "$slug/"* && "/$path" != */.* ]]; then
		for pattern in "${shipped_paths[@]}"; do
			# shellcheck disable=SC2053 # $pattern is matched as a glob on purpose.
			if [[ "$path" == $pattern ]]; then
				shipped=true
				break
			fi
		done
	fi
	[[ "$shipped" == true ]] || unexpected+=("$entry")
done <<<"$entries"

if ((${#unexpected[@]})); then
	echo "These paths would ship in $zip_name but are not expected:" >&2
	printf '  %s\n' "${unexpected[@]}" >&2
	fail "export-ignore them in .gitattributes, or add them to shipped_paths in $0."
fi

grep -qx "$slug/$slug.php" <<<"$entries" || fail "$zip_name has no $slug/$slug.php."

# unzip -Z shows symbolic links with an l in the first column.
if grep -q '^l' <<<"$(unzip -Z "dist/$zip_name")"; then
	fail "$zip_name contains a symbolic link."
fi

(cd dist && sha256sum "$zip_name" >"$zip_name.sha256")

echo "Built dist/$zip_name from $commit ($(wc -l <<<"$entries") entries)."
cat "dist/$zip_name.sha256"
