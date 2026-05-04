#!/usr/bin/env bash
# HTTP-level smoke test for the PWA manifest endpoint personalization
# (commit 17b48dea + Phase-3 follow-ups).
#
# What the unit tests in tests/PwaPersonalizeManifestTest.php do NOT
# cover and this script does:
#   - the dev-instance HTTP stack (Traefik → Apache → PHP) actually
#     reaches manifestAction with the expected Run/code resolution
#   - response headers (Content-Type, Cache-Control)
#   - regex + DB validation of `?code=` end-to-end against
#     survey_run_sessions
#   - the cookie self-heal redirect chain in indexAction
#
# Pre-reqs:
#   - dev docker stack up (formr_app + formr_db reachable)
#   - a PWA-enabled run on dev with at least one session row (we use
#     appstinence-v2 by default; override with PWA_TEST_RUN= /
#     PWA_TEST_CODE= when running against a different fixture)
#
# Exit code:
#   0  all assertions passed
#   1  at least one assertion failed (line + message printed to stderr)

set -euo pipefail
cd "$(dirname "$0")/.."

: "${PWA_TEST_HOST:=https://study.researchmixtape.com}"
: "${PWA_TEST_RUN:=appstinence-v2}"

# Look up a real session code for the fixture run if the caller didn't
# supply one. Falls back to a hardcoded test code on the dev DB if the
# query produces nothing useful.
if [[ -z "${PWA_TEST_CODE:-}" ]]; then
    PWA_TEST_CODE=$(docker exec formr_db sh -c \
        'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -N -B -e "
            SELECT srs.session FROM survey_run_sessions srs
            JOIN survey_runs sr ON sr.id = srs.run_id
            WHERE sr.name = \"'"$PWA_TEST_RUN"'\" AND srs.testing = 1
            ORDER BY srs.id DESC LIMIT 1;"' 2>/dev/null | tail -1)
fi
if [[ -z "${PWA_TEST_CODE:-}" || "${PWA_TEST_CODE}" == "NULL" ]]; then
    echo "ERROR: no test session code for run '$PWA_TEST_RUN'. Set PWA_TEST_CODE=." >&2
    exit 1
fi

BASE="$PWA_TEST_HOST/$PWA_TEST_RUN"
FAILS=0
pass() { printf '  ✓ %s\n' "$1"; }
fail() { printf '  ✗ %s\n    %s\n' "$1" "$2" >&2; FAILS=$((FAILS+1)); }

# 1) clean fetch — un-personalized manifest, public cache headers.
echo "[1] clean fetch ($BASE/manifest)"
TMP=$(mktemp); trap "rm -f $TMP" EXIT
HEADERS=$(curl -sS -D - -o "$TMP" "$BASE/manifest")
if echo "$HEADERS" | grep -qiE '^content-type: *application/manifest\+json'; then
    pass "Content-Type is application/manifest+json"
else
    fail "Content-Type wrong" "$(echo "$HEADERS" | grep -i content-type)"
fi
if echo "$HEADERS" | grep -qiE '^cache-control: *private, *no-store'; then
    pass "Cache-Control is 'private, no-store'"
else
    fail "Cache-Control wrong" "$(echo "$HEADERS" | grep -i cache-control)"
fi
START=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("start_url",""))' "$TMP")
ID=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("id",""))' "$TMP")
if [[ "$START" != *'?code='* && "$START" == *"$PWA_TEST_RUN"* ]]; then
    pass "start_url is clean (no ?code=)"
else
    fail "start_url unexpectedly tokenized or run-mismatch" "$START"
fi
if [[ "$ID" == "$START" ]]; then
    pass "id == start_url"
else
    fail "id should match start_url" "id=$ID start=$START"
fi

# 2) tokenized fetch with a valid code — start_url/id/shortcuts/protocol gain ?code=.
echo "[2] tokenized fetch ($BASE/manifest?code=<valid>)"
curl -sS -o "$TMP" "$BASE/manifest?code=$PWA_TEST_CODE"
START_T=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("start_url",""))' "$TMP")
SC1=$(python3 -c 'import json,sys; m=json.load(open(sys.argv[1])); print(m.get("shortcuts",[{}])[0].get("url",""))' "$TMP")
PH1=$(python3 -c 'import json,sys; m=json.load(open(sys.argv[1])); print(m.get("protocol_handlers",[{}])[0].get("url",""))' "$TMP")
SCOPE_T=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("scope",""))' "$TMP")
if [[ "$START_T" == *"?code=$PWA_TEST_CODE" ]]; then
    pass "start_url ends with ?code=$PWA_TEST_CODE"
else
    fail "start_url not tokenized" "$START_T"
fi
if [[ "$SCOPE_T" != *"code="* ]]; then
    pass "scope does NOT carry ?code= (must be path-prefix only)"
else
    fail "scope leaked code" "$SCOPE_T"
fi
if [[ "$SC1" == *"code=$PWA_TEST_CODE"* ]]; then
    pass "shortcut[0].url carries code"
else
    fail "shortcut[0].url missing code" "$SC1"
fi
if [[ "$PH1" == *"&code=$PWA_TEST_CODE"* ]]; then
    pass "protocol_handlers[0].url uses & separator (existing query)"
else
    fail "protocol_handlers[0].url separator wrong" "$PH1"
fi

# 3) bogus code — 404, NOT 200 with un-personalized fallback.
echo "[3] bogus code ($BASE/manifest?code=BOGUS)"
CODE=$(curl -sS -o /dev/null -w '%{http_code}' "$BASE/manifest?code=BOGUS")
if [[ "$CODE" == "404" ]]; then
    pass "bogus code → 404"
else
    fail "bogus code wrong status" "$CODE"
fi

# 4) cookie self-heal — bare URL with cookie set from a prior tokenized
#    visit should 302 to the same URL with ?code= appended, no loop.
echo "[4] cookie self-heal redirect"
COOKIE=$(mktemp); trap "rm -f $TMP $COOKIE" EXIT
curl -sS -o /dev/null -c "$COOKIE" -b "$COOKIE" "$BASE/?code=$PWA_TEST_CODE"
LOC=$(curl -sS -D - -o /dev/null -b "$COOKIE" "$BASE/" | grep -i '^location:' | head -1 | tr -d '\r' | sed 's/^[Ll]ocation: //')
if [[ "$LOC" == *"code=$PWA_TEST_CODE"* && "$LOC" != *"route="* ]]; then
    pass "302 location carries ?code= and no route= leak"
else
    fail "self-heal redirect malformed" "$LOC"
fi
REDIRECTS=$(curl -sS -o /dev/null -L -b "$COOKIE" -w '%{num_redirects}' "$BASE/")
if [[ "$REDIRECTS" == "1" ]]; then
    pass "no redirect loop (1 hop, 2xx terminus)"
else
    fail "redirect count unexpected" "$REDIRECTS"
fi

echo
if [[ $FAILS -eq 0 ]]; then
    echo "✓ all PWA manifest smoke checks passed"
    exit 0
else
    echo "✗ $FAILS failure(s)" >&2
    exit 1
fi
