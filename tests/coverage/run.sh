#!/usr/bin/env bash
# The coverage pass: what the test suites execute, merged across lanes.
# See tests/coverage/README.md. Run from the repo root on the dev-remote
# stack (formr_source bind-mounted into formr_app):
#
#   tests/coverage/run.sh            unit + smokes + e2e (about an hour)
#   tests/coverage/run.sh --no-e2e   unit + smokes only (a minute)
#
# The e2e lane recreates formr_app with Xdebug in coverage mode
# (compose-coverage.yml) and recreates it without on exit, even on failure.
# Don't edit webroot/assets while it runs: the JS report maps coverage onto
# the dev-build bundle on disk and refuses one that changed.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
DOCKER_DIR=$(cd "$ROOT/.." && pwd)
OUT="$ROOT/.coverage"
IN_APP=/var/www/formr
E2E=1
[ "${1:-}" = "--no-e2e" ] && E2E=0

# The container writes as www-data (php-html/ is not ours to delete).
docker exec -u root formr_app rm -rf $IN_APP/.coverage 2>/dev/null || true
rm -rf "$OUT"
mkdir -p "$OUT/php" "$OUT/js"
chmod -R 777 "$OUT"   # written by www-data inside formr_app

echo "== unit lane"
docker exec -e XDEBUG_MODE=coverage formr_app sh -c "cd $IN_APP && php vendor/bin/phpunit \
    --configuration tests/phpunit.xml --exclude-group integration \
    --coverage-filter application --coverage-php .coverage/php/unit.cov" | tail -3

echo "== smokes"
for f in "$ROOT"/bin/*_smoke.php; do
    rel=${f#"$ROOT/"}
    docker exec -e XDEBUG_MODE=coverage -e FORMR_COVERAGE_DIR=$IN_APP/.coverage/php \
        -e FORMR_COVERAGE_LABEL=smoke formr_app \
        php -d auto_prepend_file=$IN_APP/tests/coverage/prepend.php "$IN_APP/$rel" >/dev/null 2>&1 \
        || echo "   $rel failed (its lines are still recorded)"
done

if [ "$E2E" = 1 ]; then
    CF=$(grep '^COMPOSE_FILE=' "$DOCKER_DIR/.env" | cut -d= -f2)
    restore() {
        echo "== restoring formr_app"
        (cd "$DOCKER_DIR" && COMPOSE_FILE="$CF" docker compose up -d --no-deps --force-recreate formr_app)
    }
    trap restore EXIT
    echo "== e2e (formr_app in coverage mode)"
    (cd "$DOCKER_DIR" && COMPOSE_FILE="$CF:formr_source/tests/coverage/compose-coverage.yml" \
        docker compose up -d --no-deps --force-recreate formr_app)
    sleep 8
    (cd "$ROOT" && FORMR_JS_COVERAGE="$OUT/js" npx playwright test \
        --config tests/e2e/playwright.config.js --project=local-chromium --project=sw-chromium) \
        || echo "   some e2e tests failed; coverage is still reported"
fi

echo "== reports"
docker exec -e XDEBUG_MODE=coverage formr_app php $IN_APP/tests/coverage/php-report.php
[ "$E2E" = 1 ] && node "$ROOT/tests/coverage/js-report.mjs" "$OUT"
node --test --experimental-test-coverage "$ROOT"/tests/js/*.test.mjs 2>&1 \
    | grep -E '^ℹ (file|.*\.m?js)' > "$OUT/node-summary.txt" || true
echo "done: $OUT (php-summary.txt, php-html/, php-methods.tsv, js-summary.txt, js-uncovered.tsv, node-summary.txt)"
