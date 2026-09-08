#!/usr/bin/env bash
#
# Worker-mode smoke check: boots the sandbox under FrankenPHP with a single
# worker and asserts that the same URL renders the same bytes on its second and
# third pass through a process that has meanwhile served other pages.
#
# What it is looking for is one bug: a service that caches something
# request-scoped. Under PHP-FPM that cache dies with the request and nobody
# notices; under a worker it is handed to the next visitor. The packages' three
# `CrossRequestStateTest` suites catch that statically, by refusing state that is
# neither resettable nor declared. This is the other half — the same claim, made
# against a real kernel answering real requests.
#
#   ./bin/worker-smoke.sh [page-id]
#
# The page id defaults to a page translated into more than one locale and
# carrying a German text field: interleaving locales is what makes a leaked
# translation cache visible, and that field is what the staleness probe rewrites. Requires a warm `prod` cache (the script builds one) and the database
# the sandbox normally uses.
#
set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"
PORT="${CB_WORKER_PORT:-8005}"
BASE="http://127.0.0.1:${PORT}"

# Ports 8001-8004 belong to the Playwright fixtures and the other sandboxes.
if [ "$PORT" -ge 8001 ] && [ "$PORT" -le 8004 ]; then
    echo "Port ${PORT} is reserved by the test fixtures; pick another." >&2
    exit 2
fi

command -v frankenphp >/dev/null || { echo "frankenphp is not installed." >&2; exit 2; }

PAGE_ID="${1:-}"
if [ -z "$PAGE_ID" ]; then
    # One line on purpose: dbal:run-sql decides whether a statement returns
    # rows by looking at how the string starts, so a leading newline turns a
    # SELECT into "0 rows affected".
    PAGE_ID=$(php bin/console dbal:run-sql "SELECT p.id FROM app_page p JOIN cb_section s ON s.content_area_id = p.content_area_id JOIN cb_column c ON c.section_id = s.id JOIN cb_block b ON b.column_id = c.id JOIN cb_block_translation t ON t.block_id = b.id GROUP BY p.id HAVING COUNT(DISTINCT t.locale) > 1 AND SUM(t.locale = 'de' AND JSON_EXTRACT(t.published_values, '\$.text') IS NOT NULL) > 0 ORDER BY p.id LIMIT 1" 2>/dev/null | sed -n '4p' | tr -dc '0-9' || true)
fi
[ -n "$PAGE_ID" ] || { echo "No page with translations in two locales; pass a page id." >&2; exit 2; }

echo "→ page ${PAGE_ID}, port ${PORT}"

APP_ENV=prod APP_DEBUG=0 php bin/console cache:clear --env=prod -q

LOG=$(mktemp)
APP_ENV=prod APP_DEBUG=0 \
CB_WORKER_FILE="${ROOT}/public/frankenphp-worker.php" \
CB_WORKER_ROOT="${ROOT}/public" \
CB_WORKER_LISTEN="http://127.0.0.1:${PORT}" \
CB_WORKER_NUM=1 \
    frankenphp run --config Caddyfile.worker >"$LOG" 2>&1 &
WORKER_PID=$!
trap 'kill "$WORKER_PID" 2>/dev/null || true; rm -f "$LOG"' EXIT

for _ in $(seq 1 40); do
    curl -fsS -o /dev/null "${BASE}/" 2>/dev/null && break
    sleep 0.5
done

# Worker mode on, or the rest of this script proves nothing: in classic mode
# every request boots its own kernel and no state could leak even if it wanted
# to. The counter comes from the worker loop itself.
first=$(curl -fsS -D - -o /dev/null "${BASE}/" | grep -i '^x-cb-worker-requests:' | tr -d '\r' | awk '{print $2}')
second=$(curl -fsS -D - -o /dev/null "${BASE}/" | grep -i '^x-cb-worker-requests:' | tr -d '\r' | awk '{print $2}')
if [ -z "${second:-}" ] || [ "$second" -le "${first:-0}" ]; then
    echo "FAIL: the server is not in worker mode (no increasing X-CB-Worker-Requests)." >&2
    tail -20 "$LOG" >&2
    exit 1
fi
echo "✓ worker mode: one process, request counter ${first} → ${second}"

# Public pages only. The builder and the workbench mint a CSRF token per
# session, so their bytes legitimately differ between two cookie-less requests;
# they are checked for status further down.
URLS=(
    "/"
    "/page/${PAGE_ID}"
    "/en/page/${PAGE_ID}"
    "/de/page/${PAGE_ID}"
    "/es/page/${PAGE_ID}"
    "/_content-blocks/public/layout"
    "/_content-blocks/public/styling"
)

declare -A BASELINE
status=0

for pass in 1 2 3; do
    for url in "${URLS[@]}"; do
        body=$(curl -fsS "${BASE}${url}" || true)
        if [ -z "$body" ]; then
            echo "FAIL ${url}: empty or failed response on pass ${pass}" >&2
            status=1
            continue
        fi
        hash=$(printf '%s' "$body" | sha256sum | cut -d' ' -f1)
        if [ "$pass" = 1 ]; then
            BASELINE["$url"]="$hash"
        elif [ "${BASELINE[$url]}" != "$hash" ]; then
            echo "FAIL ${url}: pass ${pass} differs from the first render." >&2
            echo "      Something cached on an earlier request bled into this one." >&2
            status=1
        fi
    done
done
if [ "$status" = 0 ]; then echo "✓ ${#URLS[@]} public URLs render identically across three interleaved passes"; fi

# Admin surfaces: status only, but they exercise the builder shell, the section
# library and the translation workbench inside the same long-lived process.
for url in "/admin/page/${PAGE_ID}" "/admin/translations/workbench/${PAGE_ID}/de" "/admin/translations/providers" "/_content-blocks/assets/report"; do
    for pass in 1 2; do
        code=$(curl -s -o /dev/null -w '%{http_code}' "${BASE}${url}")
        # The workbench is keyed by content area, not page: a 404 there means
        # the id does not name an area, not that the worker misbehaved.
        if [ "$code" != "200" ] && [ "$code" != "404" ]; then
            echo "FAIL ${url}: HTTP ${code} on pass ${pass}" >&2
            status=1
        fi
    done
done
if [ "$status" = 0 ]; then echo "✓ admin surfaces answer on a reused kernel"; fi

# ---------------------------------------------------------------------------
# The sharp one: change a translation *out of band* — straight in the database,
# by a process the worker knows nothing about — and require the very next
# request to show it.
#
# Rendering identical bytes three times proves a cache is stable; it does not
# prove it is fresh. A translation cache that survives `kernel.terminate` keeps
# answering with the row it loaded on the first request, and the only way to see
# that is to make the database disagree with it. Removing
# `TranslationStore::reset()` fails exactly here and nowhere else.
PROBE=' ~cb-worker-probe~'
PROBE_ROW=$(php bin/console dbal:run-sql "SELECT t.id FROM app_page p JOIN cb_section s ON s.content_area_id = p.content_area_id JOIN cb_column c ON c.section_id = s.id JOIN cb_block b ON b.column_id = c.id JOIN cb_block_translation t ON t.block_id = b.id WHERE p.id = ${PAGE_ID} AND t.locale = 'de' AND JSON_EXTRACT(t.published_values, '\$.text') IS NOT NULL ORDER BY t.id LIMIT 1" 2>/dev/null | sed -n '4p' | tr -dc '0-9' || true)

probe_sql() {
    php bin/console dbal:run-sql "$1" >/dev/null 2>&1 || true
}
restore_probe() {
    [ -n "${PROBE_ROW:-}" ] || return 0
    probe_sql "UPDATE cb_block_translation SET published_values = JSON_SET(published_values, '\$.text', TRIM(TRAILING '${PROBE}' FROM JSON_UNQUOTE(JSON_EXTRACT(published_values, '\$.text')))) WHERE id = ${PROBE_ROW}"
}

if [ -n "$PROBE_ROW" ]; then
    trap 'restore_probe; kill "$WORKER_PID" 2>/dev/null || true; rm -f "$LOG"' EXIT

    probe_sql "UPDATE cb_block_translation SET published_values = JSON_SET(published_values, '\$.text', CONCAT(JSON_UNQUOTE(JSON_EXTRACT(published_values, '\$.text')), '${PROBE}')) WHERE id = ${PROBE_ROW}"

    if curl -fsS "${BASE}/de/page/${PAGE_ID}" | grep -qF "${PROBE# }"; then
        echo "✓ an out-of-band content change is visible to the next request"
    else
        echo "FAIL: the worker served a stale translation after the database changed." >&2
        echo "      A per-request cache is surviving kernel.terminate — check ResetInterface." >&2
        status=1
    fi

    restore_probe
    PROBE_ROW=''
else
    echo "· skipped the staleness probe: page ${PAGE_ID} has no German text field"
fi

if grep -qiE '"level":"(error|panic|fatal)"' "$LOG"; then
    echo "FAIL: the worker logged errors:" >&2
    grep -iE '"level":"(error|panic|fatal)"' "$LOG" | head -5 >&2
    status=1
fi

if [ "$status" = 0 ]; then echo "PASS: no state leaked between requests."; fi
exit "$status"
