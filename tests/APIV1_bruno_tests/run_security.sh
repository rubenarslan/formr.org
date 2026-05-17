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

# 1) Mint OAuth credentials inside the formr_app container for both the
#    "attacker" admin (user 1, the test admin) and a freshly-seeded "victim"
#    admin. Two-admin fixture is required so cross-user write attacks (e.g.
#    Email::create's account_id ownership, Survey::create's study_id
#    ownership) can be exercised end-to-end in Bruno — single-user attacks
#    can demonstrate mass-assignment side-effects (Mass-Assign suite) but
#    not cross-tenant relinking.
#
#    Doing this via the PHP CLI rather than the admin AJAX rotate path
#    because the latter requires a session cookie (login form) — too much
#    state to thread through a CI script — and because it lets us do
#    user-creation + OAuth provisioning atomically inside one PHP run.
echo '[setup] Provisioning attacker + victim admins and OAuth credentials...'
docker exec formr_app php -r '
require "/var/www/formr/setup.php";

function ensure_admin($email, $admin_level = 2) {
    $db = DB::getInstance();
    $row = $db->findRow("survey_users", ["email" => $email]);
    if (!$row) {
        $db->insert("survey_users", [
            "email" => $email,
            "user_code" => bin2hex(random_bytes(20)),
            // Login is not used by the test suite (OAuth client_credentials
            // grant only); password is set to a fresh random hash purely
            // so the column NOT NULL semantics are honored.
            "password" => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            "admin" => $admin_level,
            "email_verified" => 1,
            "created" => mysql_now(),
            "modified" => mysql_now(),
        ]);
        return (int) $db->lastInsertId();
    }
    if ((int) $row["admin"] < $admin_level) {
        $db->update("survey_users", ["admin" => $admin_level], ["id" => $row["id"]]);
    }
    return (int) $row["id"];
}

// Default scopes for the main attacker/victim clients used by the
// pre-existing Mass-Assign + Email-Account suites. Must match the
// hard-coded scope assertion in 01_Auth/authentificate.bru (ordering
// matters — OAuthHelper stores the scope string verbatim).
const FULL_SCOPES = ["user:read","user:write","survey:read","survey:write","run:read","run:write","session:read","session:write","data:read","file:read","file:write"];

function mint_oauth($user_id, array $scopes = FULL_SCOPES, array $run_ids = []) {
    $u = new User($user_id);
    if (!$u->canAccessApi()) { throw new RuntimeException("user $user_id lacks canAccessApi"); }
    $existing = OAuthHelper::getInstance()->getClient($u);
    $res = $existing
        ? OAuthHelper::getInstance()->refreshToken($u, $scopes, $run_ids)
        : OAuthHelper::getInstance()->createClient($u, $scopes, $run_ids);
    if (!$res) {
        throw new RuntimeException("failed to mint OAuth client for user $user_id");
    }
    return [
        "client_id" => $res["client_id"],
        "client_secret" => $res["client_secret"]->getString(),
    ];
}

// Mint an additional oauth_clients row for a user, bypassing
// OAuthHelpers one-client-per-user constraint. Used only by the
// scoping suite, which needs four distinct credentials (read-only,
// write-only, run-allowlisted, unrestricted) for the same admin to
// exercise the new scope chokepoints in ApiBase / SurveyResource /
// RunResource without managing four separate test users.
function mint_extra_client($user_id, array $scopes, array $run_ids) {
    $u = new User($user_id);
    $db = DB::getInstance();
    $client_id = bin2hex(random_bytes(16));
    $client_secret = bin2hex(random_bytes(32));
    // SHA-256 hash matches HashedTokenOAuth2StoragePdo::hashToken so
    // the bshaffer client_credentials grant validates it normally.
    $db->insert("oauth_clients", [
        "client_id" => $client_id,
        "client_secret" => hash("sha256", $client_secret),
        "redirect_uri" => "https://formr.org",
        "scope" => implode(" ", $scopes),
        "user_id" => $u->email,
    ]);
    foreach ($run_ids as $rid) {
        $db->insert("oauth_client_runs", [
            "client_id" => $client_id,
            "run_id" => (int) $rid,
        ]);
    }
    return ["client_id" => $client_id, "client_secret" => $client_secret];
}

function seed_scoping_fixtures($user_id) {
    $db = DB::getInstance();
    // Two runs owned by the attacker — one inside the allowlist, one
    // outside. Idempotent: drop+recreate so re-running the suite picks
    // up any schema changes.
    foreach (["scope-run-a","scope-run-b"] as $name) {
        $db->exec("DELETE FROM survey_runs WHERE name = " . $db->pdo()->quote($name) . " AND user_id = " . (int) $user_id);
    }
    $db->insert("survey_runs", [
        "user_id" => $user_id, "name" => "scope-run-a",
        "created" => mysql_now(), "modified" => mysql_now(),
    ]);
    $run_a_id = (int) $db->lastInsertId();
    $db->insert("survey_runs", [
        "user_id" => $user_id, "name" => "scope-run-b",
        "created" => mysql_now(), "modified" => mysql_now(),
    ]);
    $run_b_id = (int) $db->lastInsertId();

    // Surveys: scope_survey_a in run-a, scope_survey_b in run-b,
    // scope_orphan in neither.
    // survey-type units share their id with survey_studies.id (see
    // application/Model/Run.php:907 join), so we insert a survey_units
    // row first to get the id, then a survey_studies row with the same
    // id, then a survey_run_units row to link it into the run.
    $surveys = [];
    foreach ([
        ["scope_survey_a", $run_a_id],
        ["scope_survey_b", $run_b_id],
        ["scope_orphan",   null],
    ] as [$sname, $linked_run_id]) {
        $db->exec("DELETE FROM survey_studies WHERE name = " . $db->pdo()->quote($sname) . " AND user_id = " . (int) $user_id);
        $db->insert("survey_units", ["type" => "Survey", "created" => mysql_now(), "modified" => mysql_now()]);
        $sid = (int) $db->lastInsertId();
        $db->insert("survey_studies", [
            "id" => $sid, "user_id" => $user_id, "name" => $sname,
            "results_table" => "s{$sid}_{$sname}", "valid" => 1,
            "created" => mysql_now(), "modified" => mysql_now(),
        ]);
        if ($linked_run_id) {
            $db->insert("survey_run_units", [
                "run_id" => $linked_run_id, "unit_id" => $sid, "position" => 10,
            ]);
        }
        $surveys[$sname] = $sid;
    }
    return [
        "run_a_id" => $run_a_id, "run_b_id" => $run_b_id,
        "survey_a_id" => $surveys["scope_survey_a"],
        "survey_b_id" => $surveys["scope_survey_b"],
        "survey_orphan_id" => $surveys["scope_orphan"],
    ];
}

$attacker_id = 1;  // pre-existing test admin (rform@researchmixtapes.com)
$victim_id   = ensure_admin("victim@security-test.local", 2);

// Seed a victim-owned email account so the cross-user account_id link
// attack (Email::create) has a concrete victim to point at. Idempotent.
$db = DB::getInstance();
$victim_email_account_id = (int) $db->findValue(
    "survey_email_accounts",
    ["user_id" => $victim_id, "deleted" => 0],
    "id"
);
if (!$victim_email_account_id) {
    $db->insert("survey_email_accounts", [
        "user_id" => $victim_id,
        "from" => "victim-from@security-test.local",
        "from_name" => "Victim",
        "host" => "smtp.example.invalid",
        "port" => 587,
        "tls" => 1,
        "username" => "victim",
        "password" => "fake-not-used-by-tests",
        "auth_key" => bin2hex(random_bytes(16)),
        "deleted" => 0,
        "status" => 0,
        "created" => mysql_now(),
        "modified" => mysql_now(),
    ]);
    $victim_email_account_id = (int) $db->lastInsertId();
}

$attacker = mint_oauth($attacker_id);
$victim   = mint_oauth($victim_id);

// Scoping fixtures: 2 runs (one in the test allowlist, one out), 3
// surveys (one in each run, one orphan), and 4 extra OAuth clients for
// the attacker covering the meaningful matrix:
//   - ro_client: run:read only, allowlisted to run-a
//   - wo_client: run:write only, allowlisted to run-a
//   - allow_client: run:read + run:write + survey:read, allowlisted to run-a
//   - unrestricted_client: run:read + survey:read, no run allowlist
$scoping_fix = seed_scoping_fixtures($attacker_id);
$ro_client   = mint_extra_client($attacker_id, ["run:read"], [$scoping_fix["run_a_id"]]);
$wo_client   = mint_extra_client($attacker_id, ["run:write"], [$scoping_fix["run_a_id"]]);
$allow_client = mint_extra_client($attacker_id,
    ["run:read","run:write","survey:read"],
    [$scoping_fix["run_a_id"]]);
$unrestricted_client = mint_extra_client($attacker_id,
    ["run:read","survey:read"],
    []);

echo json_encode(array_merge([
    "attacker_user_id"        => $attacker_id,
    "client_id"               => $attacker["client_id"],
    "client_secret"           => $attacker["client_secret"],
    "victim_user_id"          => $victim_id,
    "victim_email"            => "victim@security-test.local",
    "victim_email_account_id" => $victim_email_account_id,
    "victim_client_id"        => $victim["client_id"],
    "victim_client_secret"    => $victim["client_secret"],
    "ro_client_id"            => $ro_client["client_id"],
    "ro_client_secret"        => $ro_client["client_secret"],
    "wo_client_id"            => $wo_client["client_id"],
    "wo_client_secret"        => $wo_client["client_secret"],
    "allow_client_id"         => $allow_client["client_id"],
    "allow_client_secret"     => $allow_client["client_secret"],
    "unrestricted_client_id"  => $unrestricted_client["client_id"],
    "unrestricted_client_secret" => $unrestricted_client["client_secret"],
], $scoping_fix));
' > /tmp/.formr_security_creds.json
chmod 600 /tmp/.formr_security_creds.json
CLIENT_ID=$(python3 -c 'import json; print(json.load(open("/tmp/.formr_security_creds.json"))["client_id"])')
CLIENT_SECRET=$(python3 -c 'import json; print(json.load(open("/tmp/.formr_security_creds.json"))["client_secret"])')
VICTIM_USER_ID=$(python3 -c 'import json; print(json.load(open("/tmp/.formr_security_creds.json"))["victim_user_id"])')
VICTIM_CLIENT_ID=$(python3 -c 'import json; print(json.load(open("/tmp/.formr_security_creds.json"))["victim_client_id"])')
VICTIM_CLIENT_SECRET=$(python3 -c 'import json; print(json.load(open("/tmp/.formr_security_creds.json"))["victim_client_secret"])')
VICTIM_EMAIL_ACCOUNT_ID=$(python3 -c 'import json; print(json.load(open("/tmp/.formr_security_creds.json"))["victim_email_account_id"])')

# Scoping suite extras.
read_json() { python3 -c "import json,sys; print(json.load(open('/tmp/.formr_security_creds.json'))['$1'])"; }
RO_CLIENT_ID=$(read_json ro_client_id)
RO_CLIENT_SECRET=$(read_json ro_client_secret)
WO_CLIENT_ID=$(read_json wo_client_id)
WO_CLIENT_SECRET=$(read_json wo_client_secret)
ALLOW_CLIENT_ID=$(read_json allow_client_id)
ALLOW_CLIENT_SECRET=$(read_json allow_client_secret)
UNRESTRICTED_CLIENT_ID=$(read_json unrestricted_client_id)
UNRESTRICTED_CLIENT_SECRET=$(read_json unrestricted_client_secret)
RUN_A_ID=$(read_json run_a_id)
RUN_B_ID=$(read_json run_b_id)
SURVEY_A_ID=$(read_json survey_a_id)
SURVEY_B_ID=$(read_json survey_b_id)
SURVEY_ORPHAN_ID=$(read_json survey_orphan_id)

echo "[setup] attacker user 1, victim user $VICTIM_USER_ID, victim email account $VICTIM_EMAIL_ACCOUNT_ID"
echo "[setup] scoping: run-a=$RUN_A_ID run-b=$RUN_B_ID survey-a=$SURVEY_A_ID survey-b=$SURVEY_B_ID orphan=$SURVEY_ORPHAN_ID"

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
    echo '[teardown] Cleaning up seeded + polluted rows + victim admin + scoping fixtures...'
    docker exec formr_db sh -c '
mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "
-- Scoping fixtures (drop runs first; survey_run_units has FK to
-- survey_runs but the schema doesnt declare ON DELETE CASCADE, so we
-- explicitly clean both. Extra oauth clients we created direct-SQL for
-- the scoping suite cascade through oauth_client_runs via the FK on
-- patch 052.)
DELETE FROM oauth_clients WHERE user_id = \"rform@researchmixtapes.com\" AND scope IS NOT NULL AND scope <> \"user:read user:write survey:read survey:write run:read run:write session:read session:write data:read file:read file:write\";
DELETE FROM survey_run_units WHERE run_id IN (SELECT id FROM survey_runs WHERE name IN (\"scope-run-a\",\"scope-run-b\") AND user_id = 1);
DELETE FROM survey_runs WHERE name IN (\"scope-run-a\",\"scope-run-b\") AND user_id = 1;
DELETE FROM survey_studies WHERE name IN (\"scope_survey_a\",\"scope_survey_b\",\"scope_orphan\") AND user_id = 1;
DELETE FROM survey_studies WHERE name IN (\"sec_victim\",\"PWNED\",\"sec_attacker_decoy\") AND user_id=1;
DELETE FROM survey_units WHERE id NOT IN (SELECT id FROM survey_studies UNION SELECT unit_id FROM survey_run_units WHERE unit_id IS NOT NULL) AND type=\"Survey\";
-- Victim admin: drop OAuth client + tokens first (FK from oauth_*tables to
-- oauth_clients via client_id; tokens reference user_id by email column),
-- then their email accounts (FK from survey_email_accounts.user_id), then
-- the user row.
DELETE oc, oat, ort, oac
  FROM oauth_clients oc
  LEFT JOIN oauth_access_tokens oat ON oat.client_id = oc.client_id
  LEFT JOIN oauth_refresh_tokens ort ON ort.client_id = oc.client_id
  LEFT JOIN oauth_authorization_codes oac ON oac.client_id = oc.client_id
  WHERE oc.user_id = \"victim@security-test.local\";
DELETE FROM survey_email_accounts WHERE user_id IN
    (SELECT id FROM survey_users WHERE email = \"victim@security-test.local\");
DELETE FROM survey_users WHERE email = \"victim@security-test.local\";"' 2>/dev/null || true
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
    "09_Security/Email-Account 01 Setup Run.bru" \
    "09_Security/Email-Account 02 Attack.bru" \
    "09_Security/Email-Account 03 Verify Account Id Dropped.bru" \
    "09_Security/Email-Account 04 Cleanup Run.bru" \
    "09_Security/scoping/Scoping 30 RO Auth.bru" \
    "09_Security/scoping/Scoping 31 RO Read Allowed.bru" \
    "09_Security/scoping/Scoping 32 RO Write Denied.bru" \
    "09_Security/scoping/Scoping 33 Allow Auth.bru" \
    "09_Security/scoping/Scoping 34 Allowed Run OK.bru" \
    "09_Security/scoping/Scoping 35 Other Run Denied.bru" \
    "09_Security/scoping/Scoping 36 Survey In Allowlisted Run.bru" \
    "09_Security/scoping/Scoping 37 Survey In Other Run Denied.bru" \
    "09_Security/scoping/Scoping 38 Orphan Survey Denied.bru" \
    "09_Security/scoping/Scoping 39 Run List Filtered.bru" \
    "09_Security/scoping/Scoping 40 Unrestricted Auth.bru" \
    "09_Security/scoping/Scoping 41 Unrestricted Sees Other Run.bru" \
    --env-var "host=$DEV_API_HOST" \
    --env-var "client_id=$CLIENT_ID" \
    --env-var "client_secret=$CLIENT_SECRET" \
    --env-var "victim_survey_id=$VICTIM_ID" \
    --env-var "victim_user_id=$VICTIM_USER_ID" \
    --env-var "victim_client_id=$VICTIM_CLIENT_ID" \
    --env-var "victim_client_secret=$VICTIM_CLIENT_SECRET" \
    --env-var "victim_email_account_id=$VICTIM_EMAIL_ACCOUNT_ID" \
    --env-var "ro_client_id=$RO_CLIENT_ID" \
    --env-var "ro_client_secret=$RO_CLIENT_SECRET" \
    --env-var "wo_client_id=$WO_CLIENT_ID" \
    --env-var "wo_client_secret=$WO_CLIENT_SECRET" \
    --env-var "allow_client_id=$ALLOW_CLIENT_ID" \
    --env-var "allow_client_secret=$ALLOW_CLIENT_SECRET" \
    --env-var "unrestricted_client_id=$UNRESTRICTED_CLIENT_ID" \
    --env-var "unrestricted_client_secret=$UNRESTRICTED_CLIENT_SECRET" \
    --env-var "run_a_name=scope-run-a" \
    --env-var "run_b_name=scope-run-b" \
    --env-var "survey_a_name=scope_survey_a" \
    --env-var "survey_b_name=scope_survey_b" \
    --env-var "survey_orphan_name=scope_orphan"
