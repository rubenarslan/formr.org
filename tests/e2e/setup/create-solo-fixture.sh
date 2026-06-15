#!/usr/bin/env bash
# Create the `all_widgets_solo` e2e fixture (run `e2e-aw-solo-v2`, study
# `e2e_all_widgets_solo_v2`) by cloning the existing e2e-aw-v2 run into a
# solo-layout twin. One-time, host-side; uses in-container root creds so the
# host never handles the DB password. Idempotent: skips if it already exists.
#
# Run once after the runbook's all_widgets v2 fixture exists:
#   bash tests/e2e/setup/create-solo-fixture.sh
set -euo pipefail
SQL_DIR="$(cd "$(dirname "$0")" && pwd)"

EXISTS=$(docker exec formr_db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -N -e "SELECT COUNT(*) FROM survey_runs WHERE name=\"e2e-aw-solo-v2\""')
if [ "$EXISTS" != "0" ]; then
    echo "create-solo-fixture: e2e-aw-solo-v2 already exists — skipping."
    exit 0
fi

docker exec -i formr_db sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -t' < "$SQL_DIR/create-solo-fixture.sql"
echo "create-solo-fixture: done."
