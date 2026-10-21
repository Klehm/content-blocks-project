#!/usr/bin/env bash
#
# Serves a sandbox's public/ for the e2e suites through php-fpm behind Caddy,
# instead of `php -S`, whose forked workers segfault now and then on CI.
#
#   scripts/e2e-server.sh <port> <public dir>
#
# php-fpm is the CLI's own version, with the CLI's php.ini and conf.d, so a CI
# job's PHP version and extensions are what the browser suite exercises too.
# Caddy is `caddy` if installed, else FrankenPHP's embedded one (`frankenphp
# run`), whose own PHP is never used here. Rationale:
# docs/internals/testing.md#the-fixture-server
set -euo pipefail

port="$1"
root="$(cd "$2" && pwd)"

version="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
fpm="$(command -v "php-fpm$version" || command -v "/usr/sbin/php-fpm$version" || command -v php-fpm || true)"
[ -n "$fpm" ] || { echo "e2e-server: no php-fpm for PHP $version." >&2; exit 2; }

if command -v caddy >/dev/null; then
    caddy=(caddy run)
elif command -v frankenphp >/dev/null; then
    caddy=(frankenphp run)
else
    echo "e2e-server: neither caddy nor frankenphp is installed." >&2
    exit 2
fi

run="$(mktemp -d "${RUNNER_TEMP:-${TMPDIR:-/tmp}}/cb-e2e-server.XXXXXX")"
trap 'kill $(jobs -p) 2>/dev/null; rm -rf "$run"' EXIT
trap 'exit 143' INT TERM

cat > "$run/fpm.conf" <<CONF
[global]
; A file, relayed below: under Playwright stderr is a pipe fpm cannot reopen.
error_log = $run/fpm.log
log_level = warning
daemonize = no

[e2e]
listen = $run/fpm.sock
pm = static
pm.max_children = 16
; The suite's SANDBOX_SUFFIX (and a CI DATABASE_URL) must reach the app.
clear_env = no
catch_workers_output = yes
decorate_workers_output = no
CONF
# Diagnosis only: lets a crashing worker leave a core for gdb.
if [ -n "${E2E_CORE_DUMPS:-}" ]; then
    ulimit -c unlimited
    echo 'rlimit_core = unlimited' >> "$run/fpm.conf"
fi

cat > "$run/Caddyfile" <<CONF
{
    admin off
    auto_https off
    persist_config off
}

http://127.0.0.1:$port {
    root * $root
    php_fastcgi unix/$run/fpm.sock
    file_server
}
CONF

touch "$run/fpm.log"
tail -n +1 -F "$run/fpm.log" >&2 &

# Its stdio goes to the log, not to our caller: fpm starts a session of its
# own, so a caller killing our process group misses it, and an inherited pipe
# would then never close (Playwright waits for it forever).
PHP_INI_SCAN_DIR="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;')" \
    "$fpm" --nodaemonize --fpm-config "$run/fpm.conf" -c "$(php -r 'echo php_ini_loaded_file();')" \
    </dev/null >>"$run/fpm.log" 2>&1 &
fpm_pid=$!

# Out of our session too, so it outlives a SIGKILL of this script and stops
# fpm then; the EXIT trap only covers a clean exit.
setsid bash -c 'while kill -0 "$1" 2>/dev/null; do sleep 1; done; kill "$2" 2>/dev/null; sleep 1; rm -rf "$3"' \
    _ $$ "$fpm_pid" "$run" </dev/null >/dev/null 2>&1 &

for _ in $(seq 1 50); do
    [ -S "$run/fpm.sock" ] && break
    sleep 0.1
done
[ -S "$run/fpm.sock" ] || { echo "e2e-server: php-fpm did not start." >&2; exit 1; }

XDG_DATA_HOME="$run" XDG_CONFIG_HOME="$run" \
    "${caddy[@]}" --config "$run/Caddyfile" --adapter caddyfile &
wait -n
