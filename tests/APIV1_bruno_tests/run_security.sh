#!/usr/bin/env bash
# Run the V1 API security test suite (currently: mass-assignment regression).
#
# What this wraps that a plain `bru run` cannot:
#   1) Mints a fresh OAuth client_id / client_secret for the test admin via
#      `docker exec formr_app php -r ...`. The Bruno auth test uses these
#      to obtain the bearer token in 01_Auth/authentificate.bru.
#   2) Seeds a "victim" survey_studies row directly via SQL — bypasses the
#      multipart-upload path that triggers an xdebug-formatted PHP
#      deprecation warning out of vendor/erusev/parsedown-extra (the warning
#      is emitted to stdout *before* setStatusCode runs, which forces the
#      response to 200 and prepends ~12 KB of HTML to the JSON body, which
#      Bruno then can't parse). Direct SQL gives us a clean victim row.
#   3) Cleans up the seeded victim row (and any pollution if a buggy build
#      let the attack succeed) at the end.
#
# Pre-requisites:
#   - Run from the repo root (or from this directory; we cd to the right
#     place).
#   - Dev docker stack up (formr_app + formr_db).
#   - bru CLI available via `npx --yes @usebruno/cli@latest` (auto-installed).

set -euo pipefail

cd "$(dirname "$0")"

# Override with: DEV_API_HOST=https://example.com/api ./run_security.sh
# Default is the URL the dev formr_app container is reachable at on the
# in-house dev box; participant-facing study domain is used because the
# Traefik admin host historically intercepted /api/* (since fixed).
: "${DEV_API_HOST:=https://study.researchmixtape.com/api}"

# 1) Mint OAuth credentials directly inside the formr_app container. We cannot
#    use the admin web AJAX path (admin/account/api-credentials) here because
#    the Traefik dashboard router historically intercepted /api/* on the admin
#    host on this dev box; minting via the PHP CLI sidesteps the proxy entirely.
echo '[setup] Minting OAuth credentials...'
docker exec formr_app php -r '
require "/var/www/formr/setup.php";
$user = new User(1);
if (!$user->canAccessApi()) { fwrite(STDERR, "user 1 lacks API access\n"); exit(1); }
$existing = OAuthHelper::getInstance()->getClient($user);
$res = $existing
    ? OAuthHelper::getInstance()->refreshToken($user)
    : OAuthHelper::getInstance()->createClient($user);
echo json_encode(["client_id"=>$res["client_id"], "client_secret"=>$res["client_secret"]->getString()]);
' > /tmp/.formr_security_creds.json
chmod 600 /tmp/.formr_security_creds.json
CLIENT_ID=$(python3 -c 'import json; print(json.load(open("/tmp/.formr_security_creds.json"))["client_id"])')
CLIENT_SECRET=$(python3 -c 'import json; print(json.load(open("/tmp/.formr_security_creds.json"))["client_secret"])')

# 2) Seed the victim row. We INSERT a survey_units placeholder (FK target) and
#    then a survey_studies row with name='sec_victim' owned by user_id=1. The
#    attack in seq 3 tries to clobber this row's name/results_table/user_id via
#    survey_data.settings; the verify step in seq 4 GETs by the original name
#    to confirm the row was not renamed.
echo '[setup] Seeding victim row...'
VICTIM_ID=$(docker exec formr_db sh -c '
mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -N -B -e "
DELETE FROM survey_studies WHERE name IN (\"sec_victim\",\"PWNED\",\"sec_attacker_decoy\") AND user_id=1;
DELETE FROM survey_units WHERE type = \"Survey\" AND id NOT IN (SELECT id FROM survey_studies);
INSERT INTO survey_units (type, created, modified) VALUES (\"Survey\", NOW(), NOW());
SET @vid = LAST_INSERT_ID();
INSERT INTO survey_studies (id, user_id, name, results_table, valid, created, modified)
  VALUES (@vid, 1, \"sec_victim\", CONCAT(\"s\", @vid, \"_sec_victim\"), 1, NOW(), NOW());
SELECT @vid;"' 2>/dev/null | tail -1)

if [[ -z "$VICTIM_ID" || ! "$VICTIM_ID" =~ ^[0-9]+$ ]]; then
    echo "[setup] ERROR: could not seed victim row (got '$VICTIM_ID')" >&2
    exit 1
fi
echo "[setup] victim_survey_id = $VICTIM_ID"

cleanup() {
    echo '[teardown] Cleaning up seeded + polluted rows...'
    docker exec formr_db sh -c '
mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "
DELETE FROM survey_studies WHERE name IN (\"sec_victim\",\"PWNED\",\"sec_attacker_decoy\") AND user_id=1;
DELETE FROM survey_units WHERE id NOT IN (SELECT id FROM survey_studies UNION SELECT unit_id FROM survey_run_units WHERE unit_id IS NOT NULL) AND type=\"Survey\";"' 2>/dev/null || true
    rm -f /tmp/.formr_security_creds.json
}
trap cleanup EXIT

# 3) Run the auth + Mass-Assign suite with the env vars Bruno's templates need.
#    Bruno's auth test stashes the access_token; the Mass-Assign tests use it.
#    We pass the Mass-Assign files explicitly rather than the whole 09_Security
#    folder so we don't drag in `SQL Injection Attempt.bru`, which expects
#    state created by 02_Runs / 05_Run_Structure that this script doesn't seed.
echo '[run] bru run...'
npx --yes @usebruno/cli@latest run \
    "01_Auth/authentificate.bru" \
    "09_Security/Mass-Assign 01 Setup Run.bru" \
    "09_Security/Mass-Assign 02 Attack.bru" \
    "09_Security/Mass-Assign 03 Verify Victim Untouched.bru" \
    "09_Security/Mass-Assign 04 Cleanup Run.bru" \
    "09_Security/Mass-Assign 05 Cleanup Decoy Survey.bru" \
    --env-var "host=$DEV_API_HOST" \
    --env-var "client_id=$CLIENT_ID" \
    --env-var "client_secret=$CLIENT_SECRET" \
    --env-var "victim_survey_id=$VICTIM_ID"
