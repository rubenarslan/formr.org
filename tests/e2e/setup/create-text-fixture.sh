#!/usr/bin/env bash
# Create the `text_solo` e2e fixture (run `e2e-text-solo-v2`, study
# `e2e_text_solo_v2`) — a tiny TEXT-ONLY solo v2 study for the BrowserStack
# device/screenshot tests, so they don't render the OpenCPU-heavy all_widgets.
# One-time, host-side; idempotent (skips if it already exists).
#   bash tests/e2e/setup/create-text-fixture.sh
set -euo pipefail
SQL_DIR="$(cd "$(dirname "$0")" && pwd)"

EXISTS=$(docker exec formr_db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -N -e "SELECT COUNT(*) FROM survey_runs WHERE name=\"e2e-text-solo-v2\""')
if [ "$EXISTS" != "0" ]; then
    echo "create-text-fixture: e2e-text-solo-v2 already exists — skipping."
    exit 0
fi

docker exec -i formr_db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -t' < "$SQL_DIR/create-text-fixture.sql"
echo "create-text-fixture: done."
