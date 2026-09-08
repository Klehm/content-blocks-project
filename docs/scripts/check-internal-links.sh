#!/usr/bin/env bash
# Every `@see docs/internals/x.md#anchor` in the packages must resolve.
#
# The comment budget replaces long docblocks with pointers, so a pointer that
# no longer lands is the same failure as a docblock that lies — with the added
# cost that the reader now has nowhere to go. Run in CI beside PHPStan.
#
# Anchors are derived the way GitHub derives them: lowercase, apostrophes
# dropped, remaining punctuation dropped, spaces to hyphens. Underscores and
# hyphens survive — GitHub keeps word characters, and `cb_translatable` is a
# heading here.

set -uo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$root" || exit 1

refs=$(grep -rhno 'docs/internals/[a-z0-9-]*\.md\(#[a-z0-9_-]*\)\?' \
  packages/*/src packages/*/assets packages/*/templates packages/*/tests packages/*/config \
  2>/dev/null | sed 's/.*://' | sort -u)

[ -n "$refs" ] || { echo "No docs/internals pointers found."; exit 0; }

failures=0
while read -r ref; do
  [ -n "$ref" ] || continue
  file="${ref%%#*}"
  if [ ! -f "$file" ]; then
    echo "missing file    $ref"
    failures=1
    continue
  fi

  case "$ref" in
    *#*) anchor="${ref##*#}" ;;
    *) continue ;;
  esac

  if ! grep -E '^#{2,4} ' "$file" \
    | sed -E "s/^#+ //; s/'//g; s/[^a-zA-Z0-9 _-]//g; s/ +/-/g" \
    | tr 'A-Z' 'a-z' \
    | grep -qx "$anchor"; then
    echo "broken anchor   $ref"
    failures=1
  fi
done <<<"$refs"

[ "$failures" -eq 0 ] || exit 1
echo "All docs/internals pointers resolve."
