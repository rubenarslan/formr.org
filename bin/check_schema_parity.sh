#!/bin/bash
#
# Schema-baseline drift guard.
#
# sql/schema.sql is the fresh-install baseline: a brand-new instance is seeded
# from it and then the migration runner marks every patch as already-applied
# (build.sh: `atlas migrate set <highest>`) WITHOUT running them. So anything a
# patch adds that is not also folded into schema.sql is permanently absent on
# fresh installs — silently, because the ledger claims the patch ran. This
# check catches that drift by loading schema.sql into a throwaway database and
# diffing every table against a reference instance that HAS applied the patches.
#
# Run it at release time against a fully-migrated instance:
#     bin/check_schema_parity.sh                 # uses the running formr_db
#     REF_DB=formr DB_CONTAINER=formr_db bin/check_schema_parity.sh
#
# Exits non-zero if any core table drifts or is missing from schema.sql.
# Runtime study tables (s<N>_…) and non-core artifacts are ignored.
set -u

HERE="$(cd "$(dirname "$0")/.." && pwd)"
SCHEMA="${SCHEMA:-$HERE/sql/schema.sql}"
DB_CONTAINER="${DB_CONTAINER:-formr_db}"
SCRATCH="${SCRATCH_DB:-zzschemaparity_$$}"

my() { docker exec -i "$DB_CONTAINER" sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$@"' _ "$@"; }

# Reference DB = the migrated instance to diff against. Default to the
# container's own MARIADB_DATABASE so the caller needn't know the name.
REF_DB="${REF_DB:-$(docker exec -i "$DB_CONTAINER" sh -c 'printf %s "$MARIADB_DATABASE"' 2>/dev/null)}"
REF_DB="${REF_DB:-formr}"
norm() { sed -E 's/ AUTO_INCREMENT=[0-9]+//; s/[[:space:]]+$//'; }
showcreate() { # $1=db $2=table
  my -N "$1" -e "SHOW CREATE TABLE \`$2\`\G" 2>/dev/null | sed -n '/^CREATE TABLE/,$p' | norm
}

# Load schema.sql into a fresh scratch DB (redirect its hardcoded `formr` db).
my -e "DROP DATABASE IF EXISTS $SCRATCH; CREATE DATABASE $SCRATCH CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;"
sed -e 's/CREATE DATABASE IF NOT EXISTS formr[^;]*;//' -e 's/^USE formr;//' "$SCHEMA" \
  | my "$SCRATCH" 2>&1 | grep -viE 'sandbox|^$'

fails=0
# 1) every table in schema.sql must match the migrated reference exactly.
for t in $(my -N "$SCRATCH" -e "SHOW TABLES" 2>/dev/null); do
  ref="$(showcreate "$REF_DB" "$t")"
  if [ -z "$ref" ]; then
    echo "EXTRA   $t: in schema.sql but not in the reference DB"; fails=$((fails+1)); continue
  fi
  if [ "$ref" != "$(showcreate "$SCRATCH" "$t")" ]; then
    echo "DRIFT   $t:"; diff <(showcreate "$SCRATCH" "$t") <(echo "$ref") | sed 's/^/        /'
    fails=$((fails+1))
  fi
done

# 2) any reference table that the app or a patch references but schema.sql
#    lacks is missing-drift. Runtime study tables and artifacts are ignored.
for t in $(my -N "$REF_DB" -e "SHOW TABLES" 2>/dev/null); do
  case "$t" in s[0-9]*_*|PWNED_TABLE|*_tmp) continue;; esac
  grep -qE "CREATE TABLE \`$t\`" "$SCHEMA" && continue
  if grep -rqE "\b$t\b" --include='*.php' --include='*.sql' "$HERE/application" "$HERE/bin" "$HERE/sql/patches" 2>/dev/null; then
    echo "MISSING $t: referenced by code/patches but absent from schema.sql"; fails=$((fails+1))
  fi
done

my -e "DROP DATABASE IF EXISTS $SCRATCH;" 2>/dev/null
echo
[ "$fails" -eq 0 ] && echo "OK: schema.sql matches the migrated reference for all core tables" \
                   || echo "FAIL: $fails table(s) drifted/missing — fold the patch changes into sql/schema.sql"
exit $fails
