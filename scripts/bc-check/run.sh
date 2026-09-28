#!/usr/bin/env bash
# Checks each package for BC breaks against the last release tag, with roave.
# See docs/internals/bc-check.md.
#
# Usage: scripts/bc-check/run.sh [--from=<ref>] [package...]
#   package: content-blocks | content-blocks-kit | content-blocks-i18n (all)
#   ROAVE_BIN: a roave-backward-compatibility-check binary (else installed)
#   BC_FORMAT: roave output format, e.g. markdown (default: console)
set -euo pipefail

root="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
here="$root/scripts/bc-check"
page="$root/docs/guide/backward-compatibility.md"

from=""
packages=()
for arg in "$@"; do
  case "$arg" in
    --from=*) from="${arg#--from=}" ;;
    *) packages+=("$arg") ;;
  esac
done
[ ${#packages[@]} -gt 0 ] || packages=(content-blocks content-blocks-kit content-blocks-i18n)
[ -n "$from" ] || from="$(git -C "$root" describe --tags --abbrev=0 --match 'v*' HEAD)"

roave="${ROAVE_BIN:-}"
if [ -z "$roave" ]; then
  tools="$root/var/bc-check-tools"
  mkdir -p "$tools"
  [ -x "$tools/vendor/bin/roave-backward-compatibility-check" ] \
    || composer require --working-dir="$tools" --no-interaction --no-progress \
         roave/backward-compatibility-check:^8.19 >&2
  roave="$tools/vendor/bin/roave-backward-compatibility-check"
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# One package side: its composer.json and src/, at a git ref or the work tree.
export_package() { # <package> <ref|WORKTREE> <dest>
  mkdir -p "$3"
  if [ "$2" = WORKTREE ]; then
    cp -R "$root/packages/$1/composer.json" "$root/packages/$1/src" "$3/"
  else
    git -C "$root" archive "$2" "packages/$1/composer.json" "packages/$1/src" \
      | tar -x -C "$3" --strip-components=2
  fi
}

status=0
for package in "${packages[@]}"; do
  echo "::group::$package — BC against $from" >&2
  repo="$work/$package"
  git init -q "$repo"
  for side in base head; do
    ref="$from"; [ "$side" = head ] && ref=WORKTREE
    find "$repo" -mindepth 1 -maxdepth 1 ! -name .git -exec rm -rf {} +
    export_package "$package" "$ref" "$repo"
    core=()
    if [ "$package" != content-blocks ]; then
      export_package content-blocks "$ref" "$work/core-$package-$side"
      core=("$work/core-$package-$side")
    fi
    php "$here/prepare.php" "$repo" "$page" "${core[@]}"
    [ -f "$here/baseline/$package.xml" ] \
      && cp "$here/baseline/$package.xml" "$repo/.roave-backward-compatibility-check.xml"
    git -C "$repo" add -A
    git -C "$repo" -c user.name=bc -c user.email=bc@localhost commit -q --allow-empty -m "$side"
    git -C "$repo" tag "$side"
  done
  (cd "$repo" && "$roave" --from=base --to=head --format="${BC_FORMAT:-console}") \
    || status=$?
  echo "::endgroup::" >&2
done
exit "$status"
