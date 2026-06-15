#!/usr/bin/env bash
# Toggle the e2e-aw-v2 Form study's layout (solo|default) for the keyboard
# screenshot run. Uses the in-container root creds (MARIADB_ROOT_PASSWORD /
# MARIADB_DATABASE, read INSIDE formr_db) so the host never handles the
# password — and, crucially, runs OUTSIDE the BrowserStack SDK's process
# wrapper, which breaks the in-test docker-exec DB path (ERROR 2026 TLS/SSL
# broken pipe). `npm run test:bs:keyboard` calls this `solo` before the run and
# `default` after.
set -euo pipefail
VALUE="${1:-default}"
case "$VALUE" in solo|default) ;; *) echo "usage: solo-layout.sh solo|default" >&2; exit 2;; esac

Q_ID='SELECT su.form_study_id FROM survey_units su JOIN survey_run_units sru ON sru.unit_id=su.id JOIN survey_runs r ON r.id=sru.run_id WHERE r.name="e2e-aw-v2" AND su.type="Form" LIMIT 1'
SID=$(docker exec formr_db sh -c "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" \"\$MARIADB_DATABASE\" -N -e '$Q_ID'")
[ -n "$SID" ] || { echo "could not resolve e2e-aw-v2 form study id" >&2; exit 1; }
docker exec formr_db sh -c "mariadb -uroot -p\"\$MARIADB_ROOT_PASSWORD\" \"\$MARIADB_DATABASE\" -e 'UPDATE survey_studies SET layout=\"$VALUE\" WHERE id=$SID'"
echo "solo-layout: e2e-aw-v2 study $SID -> layout=$VALUE"
