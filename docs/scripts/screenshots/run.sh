#!/usr/bin/env bash
# Seeds, shoots and converts every documentation screenshot, with the sandbox
# switched to English for the run and always put back. See README.md
set -euo pipefail

here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/../../.." && pwd)"
sandbox="$root/apps/content-blocks-sandbox"
dest="$root/docs/public/screenshots"
shots="$(mktemp -d)"
port=8006
server=''

clear_cache() {
    # A second process (an IDE's test server) may race the rename: retry.
    for _ in 1 2 3; do
        php "$sandbox/bin/console" cache:clear -q 2>/dev/null \
            && php "$sandbox/bin/console" about -q && return 0
        sleep 2
    done
    return 1
}

restore() {
    [ -n "$server" ] && kill "$server" 2>/dev/null || true
    git -C "$root" checkout -- \
        apps/content-blocks-sandbox/config/packages/translation.yaml \
        apps/content-blocks-sandbox/config/packages/content_blocks_i18n.yaml
    clear_cache || echo 'warning: run cache:clear in the sandbox' >&2
}
trap restore EXIT

# English UI, English source, French as a translation target.
cd "$sandbox"
sed -i 's/^    default_locale: fr/    default_locale: en/' config/packages/translation.yaml
sed -i "s/^    source_locale: fr/    source_locale: en/; s/{ code: en, label: 'English' }/{ code: fr, label: 'Français' }/" \
    config/packages/content_blocks_i18n.yaml
clear_cache

seed="$(php "$here/seed.php" | tail -1)"
# The seed writes blocks directly; the builder would have minted these ids,
# and the workbench keys collection entries by them.
php bin/console content-blocks:backfill-collection-ids -q
php -S "127.0.0.1:$port" -t public public/router.php >/dev/null 2>&1 &
server=$!
sleep 2
node "$here/shoot.mjs" "$shots" "$seed" "http://127.0.0.1:$port"

webp() { ffmpeg -loglevel error -y -i "$1" -vf "$2" -c:v libwebp -quality "$3" "$4"; }
mkdir -p "$dest/kit/thumbs"
for f in builder section-sidebar navigator builder-mobile workbench; do
    webp "$shots/$f.png" 'scale=1920:-1' 80 "$dest/$f.webp"
done
for f in "$shots"/kit/*.png; do
    webp "$f" 'scale=1200:-1' 82 "$dest/kit/$(basename "$f" .png).webp"
done
for f in "$shots"/kit/thumbs/*.png; do
    webp "$f" "scale='min(560,iw)':-1" 82 "$dest/kit/thumbs/$(basename "$f" .png).webp"
done

(cd "$root/docs" && node scripts/gen-blocks.mjs)
echo "PNG sources kept in $shots"
