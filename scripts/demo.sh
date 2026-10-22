#!/usr/bin/env bash
#
# Starts, stops and resets the local demos (the sandboxes under apps/).
#
#   scripts/demo.sh <up|down|restart|reset|status|logs> [main|sylius|encore|shop|all]
#
# Driven by the root Makefile (`make up DEMO=sylius`). Each demo keeps its own
# port, database and server pid/log under its var/.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
action="${1:-}"
demo="${2:-main}"

# name → directory, port, entry path, build tool, seed command; the table
# probed to tell an installed database; what seeds an `_e2e` instance.
configure() {
    probe=app_page e2e_seed='' uploads=public/uploads/content-blocks
    case "$1" in
        main)   dir=content-blocks-sandbox        port=8000 entry=/       build=assetmap
                seed='bin/reset.sh --seed' ;;
        sylius) dir=content-blocks-sylius-sandbox port=8003 entry=/admin/ build=assetmap
                seed='php bin/console --no-interaction app:demo:load -q' ;;
        encore) dir=content-blocks-encore-sandbox port=8004 entry=/       build=encore
                seed='' ;;
        # Sylius-Standard: the shop needs Sylius's own fixtures, tests included.
        shop)   dir=content-blocks-sylius-shop-sandbox port=8006 entry=/en_US/ build=encore
                probe=cb_sylius_page uploads=public/media/content-blocks
                e2e_seed='php bin/console --no-interaction sylius:fixtures:load default -q'
                seed="$e2e_seed && php bin/console --no-interaction app:demo:load -q" ;;
        *) echo "Unknown demo '$1' (main, sylius, encore, shop, all)." >&2; exit 1 ;;
    esac
    port="${PORT:-$port}"
    app="$root/apps/$dir"
    url="http://127.0.0.1:$port"
    pidfile="$app/var/demo-server.pid"
    logfile="$app/var/demo-server.log"
}

console() { php "$app/bin/console" --no-interaction "$@"; }
running() { curl -s -o /dev/null "$url"; }

# Main has its own script, also used by Playwright for the `_e2e` instance.
# SANDBOX_SUFFIX set means a test instance: emptied, never seeded.
reset() {
    if [ "$1" = main ]; then (cd "$app" && bin/reset.sh --seed); return; fi
    local suffix="${SANDBOX_SUFFIX:-}"
    console doctrine:database:drop --force --if-exists -q
    console doctrine:database:create -q
    console doctrine:schema:create -q
    rm -rf "${app:?}/$uploads$suffix"
    if [ -n "$suffix" ]; then
        [ -z "$e2e_seed" ] || (cd "$app" && eval "$e2e_seed")
    else
        [ -z "$seed" ] || (cd "$app" && eval "$seed")
    fi
    echo "Demo '$1${suffix}' reset."
}

up() {
    if running; then echo "$1: already running on $url$entry"; return; fi
    [ -d "$app/vendor" ] || composer install -d "$app"
    console doctrine:database:create --if-not-exists -q
    if console dbal:run-sql -q "SELECT 1 FROM $probe LIMIT 1" >/dev/null 2>&1
    then console doctrine:schema:update --force -q
    else reset "$1"; fi

    if [ "$build" = encore ]; then
        [ -d "$app/node_modules" ] || npm install --prefix "$app"
        [ -d "$app/public/build" ] || npm run build --prefix "$app"
    else
        [ -d "$app/public/assets" ] || console asset-map:compile -q
    fi

    local router=()
    [ -f "$app/public/router.php" ] && router=("$app/public/router.php")
    mkdir -p "$app/var"
    # PHP's 2M default would refuse uploads the package accepts (10M).
    nohup php -d upload_max_filesize=12M -d post_max_size=16M \
        -S "127.0.0.1:$port" -t "$app/public" "${router[@]}" \
        > "$logfile" 2>&1 &
    echo $! > "$pidfile"
    sleep 1
    echo "$1: running on $url$entry"
}

down() {
    if [ -f "$pidfile" ] && kill "$(cat "$pidfile")" 2>/dev/null
    then echo "$1: stopped."
    elif running; then echo "$1: $url was not started by make, stop it by hand."
    else echo "$1: not running."
    fi
    rm -f "$pidfile"
}

status() {
    if running; then echo "$1: up    $url$entry"; else echo "$1: down  ($url)"; fi
}

logs() { tail -f "$logfile"; }

case "$action" in
    up|down|reset|status) ;;
    restart) "$0" down "$demo"; exec "$0" up "$demo" ;;
    logs) [ "$demo" != all ] || { echo 'logs takes one demo.' >&2; exit 1; } ;;
    *) echo "Usage: $0 <up|down|restart|reset|status|logs> [main|sylius|encore|shop|all]" >&2
       exit 1 ;;
esac

[ "$demo" = all ] && demos=(main sylius encore shop) || demos=("$demo")
[ "${#demos[@]}" -eq 1 ] || unset PORT
for d in "${demos[@]}"; do
    configure "$d"
    "$action" "$d"
done
