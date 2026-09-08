#!/usr/bin/env bash
# Comment budget: max 2 prose lines per comment block, max 80 columns.
# See the "Commentaires" section of CLAUDE.md.
#
# Reads a PostToolUse hook payload on stdin, checks the file that was just
# written, and exits 2 (feeding stderr back to Claude) when the budget is
# exceeded. Only blocks overlapping lines changed since HEAD are reported, so
# editing a file that predates the rule does not resurface its whole backlog.

set -uo pipefail

MAX_LINES=2
MAX_COLS=80
MAX_REPORTED=6

payload=$(cat)
file=$(printf '%s' "$payload" | jq -r '.tool_response.filePath // .tool_input.file_path // empty' 2>/dev/null)

[ -n "$file" ] || exit 0
[ -f "$file" ] || exit 0

case "$file" in
  */packages/*) ;;
  *) exit 0 ;;
esac
case "$file" in
  */vendor/*|*/node_modules/*|*/var/cache/*) exit 0 ;;
esac

case "$file" in
  *.php|*.js|*.css) mode=c ;;
  *.twig)           mode=twig ;;
  *) exit 0 ;;
esac

# Tests keep the column limit but not the prose budget. A comment on a test
# usually names the case it pins, which is that comment's whole job — the
# duplication the budget exists to stop lives in the code it exercises.
BUDGET="max $MAX_LINES prose lines per block, max $MAX_COLS columns"
case "$file" in
  */tests/*|*/test/*|*.spec.js|*.test.js)
    MAX_LINES=999
    BUDGET="max $MAX_COLS columns; tests are exempt from the prose budget"
    ;;
esac

# Violating blocks, one per line: "<start> <end> <reason>"
violations=$(awk -v mode="$mode" -v maxl="$MAX_LINES" -v maxc="$MAX_COLS" '
# Character width, not byte length: mawk has no multibyte support, and this
# codebase writes em-dashes and arrows. Counting bytes would penalise them.
function cols(s,  t, n) { t = s; n = gsub(/[\200-\277]/, "", t); return length(s) - n }
function flush(  reason) {
  if (blk_start == 0) return
  reason = ""
  if (prose > maxl) reason = prose " prose lines (max " maxl ")"
  if (longest > maxc) reason = reason (reason ? ", " : "") "line of " longest " cols (max " maxc ")"
  if (reason != "") print blk_start " " blk_end " " reason
  blk_start = 0; prose = 0; longest = 0; in_tags = 0
}
{
  is_comment = 0; text = ""; t = $0

  if (mode == "twig") {
    if (inblock) {
      is_comment = 1
      if (index(t, "#}") > 0) { sub(/#\}.*$/, "", t); inblock = 0 }
      sub(/^[ \t]*#?[ \t]*/, "", t)
      text = t
    } else if (t ~ /^[ \t]*\{#/) {
      is_comment = 1
      sub(/^[ \t]*\{#+[ \t]*/, "", t)
      if (index(t, "#}") > 0) sub(/#\}.*$/, "", t); else inblock = 1
      text = t
    }
  } else {
    if (inblock) {
      is_comment = 1
      if (index(t, "*/") > 0) { sub(/\*\/.*$/, "", t); inblock = 0 }
      sub(/^[ \t]*/, "", t); sub(/^\*+[ \t]*/, "", t)
      text = t
    } else if (t ~ /^[ \t]*\/\*/) {
      is_comment = 1
      if (index(t, "*/") > 0) sub(/\*\/.*$/, "", t); else inblock = 1
      sub(/^[ \t]*\/\*+[ \t]*/, "", t)
      text = t
    } else if (t ~ /^[ \t]*\/\//) {
      is_comment = 1
      sub(/^[ \t]*\/\/+[ \t]*/, "", t)
      text = t
    }
  }

  if (!is_comment) { flush(); next }

  if (blk_start == 0) blk_start = FNR
  blk_end = FNR
  if (cols($0) > longest) longest = cols($0)

  sub(/[ \t]+$/, "", text)
  # Prose is what precedes the first @tag. From there on the block is
  # annotation — including the continuation lines of a wrapped array shape,
  # which carry no leading @ of their own.
  if (text ~ /^@/) in_tags = 1
  if (!in_tags && text != "") prose++
}
END { flush() }
' "$file")

[ -n "$violations" ] || exit 0

# Restrict to blocks touching lines changed since HEAD. An untracked or
# unversioned file is reported whole — it is new, so it should already comply.
repo=$(git -C "$(dirname "$file")" rev-parse --show-toplevel 2>/dev/null)
if [ -n "$repo" ] && git -C "$repo" ls-files --error-unmatch "$file" >/dev/null 2>&1; then
  ranges=$(git -C "$repo" diff -U0 HEAD -- "$file" 2>/dev/null \
    | awk '/^@@/ { split($3, a, ","); s = a[1]; sub(/^\+/, "", s); n = (a[2] == "" ? 1 : a[2]); if (n > 0) print s " " (s + n - 1) }')
  # No diff at all means the write matched HEAD; nothing new to flag.
  [ -n "$ranges" ] || exit 0
  violations=$(awk -v ranges="$ranges" '
    BEGIN { n = split(ranges, rows, "\n"); for (i = 1; i <= n; i++) { split(rows[i], r, " "); lo[i] = r[1]; hi[i] = r[2] } }
    { for (i = 1; i <= n; i++) if ($1 <= hi[i] && $2 >= lo[i]) { print; break } }
  ' <<<"$violations")
  [ -n "$violations" ] || exit 0
fi

rel=${file#"${repo:-}/"}
{
  echo "Comment budget exceeded in $rel ($BUDGET):"
  echo "$violations" | head -n "$MAX_REPORTED" | while read -r start end reason; do
    echo "  lines $start-$end: $reason"
  done
  total=$(echo "$violations" | wc -l)
  [ "$total" -gt "$MAX_REPORTED" ] && echo "  ... and $((total - MAX_REPORTED)) more"
  echo "Trim to what the code cannot say itself; move the long rationale to docs/guide/ and leave a @see pointer."
} >&2

exit 2
