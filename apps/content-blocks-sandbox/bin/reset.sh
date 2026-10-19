#!/usr/bin/env bash
#
# Empties a sandbox instance: drops and recreates its database, deletes its
# uploads and their image variants. With --seed, adds the demo pages back.
#
#   ./bin/reset.sh [--seed]                       the demo (`make reset`)
#   SANDBOX_SUFFIX=_e2e ./bin/reset.sh            the Playwright instance
#
# The instance is chosen by SANDBOX_SUFFIX, as everywhere else: '' is the demo,
# '_e2e' the suite's. See docs/internals/testing.md#the-suite-has-its-own-data
set -euo pipefail

cd "$(dirname "$0")/.."
suffix="${SANDBOX_SUFFIX:-}"
console=(php bin/console --no-interaction)

"${console[@]}" doctrine:database:drop --force --if-exists -q
"${console[@]}" doctrine:database:create -q
"${console[@]}" doctrine:schema:create -q

rm -rf "public/uploads/content-blocks${suffix}"
# LiipImagine mirrors the source path under each filter set's directory.
rm -rf public/media/cache/*/"uploads/content-blocks${suffix}"

if [ "${1:-}" = '--seed' ]; then
    php ../../docs/scripts/screenshots/seed.php > /dev/null
    "${console[@]}" app:i18n:demo -q
fi

echo "Sandbox instance '${suffix:-demo}' reset${1:+ and seeded}."
