# Upgrading to v1.0.0

v1.0.0 reshapes OAuth credential storage in three ways that **break every
existing API integration on first apply**:

- patch `050_hash_oauth_tokens.sql` TRUNCATEs `oauth_access_tokens`,
  `oauth_refresh_tokens`, `oauth_authorization_codes` (raw tokens
  can't match the new hashed-lookup path, so they would only sit in
  the table as readable secrets);
- patch `051_hash_client_secrets.sql` zeroes every
  `oauth_clients.client_secret`;
- patch `052_oauth_client_runs.sql` clears `oauth_scopes.is_default`
  for every scope and TRUNCATEs the token tables a second time as
  belt-and-braces.

After these patches apply, every existing API client has:

- no access token (must reauthenticate),
- no client secret (must rotate from `/admin/account#api`),
- no granted scopes (must reselect at rotation time — the previous
  "all scopes by default" no longer holds).

There is no automatic migration of secrets, scopes, or run allowlists.
Plan accordingly.

## Pre-flight (do this *before* bumping `FORMR_TAG`)

1. **Inventory every consumer of `/api/...`.** Walk every cron job,
   dashboard, OSF integration, and R script that holds a long-lived
   OAuth access token or client_credentials secret. Each will need
   to be rotated. Common places to look:

   - host crontabs and systemd timers
   - CI / GitHub Actions secrets
   - team password manager entries tagged "formr"
   - R scripts that hardcode `Sys.getenv("FORMR_ACCESS_TOKEN")` or
     a client_id/client_secret pair

2. **Schedule a maintenance window.** Tokens stop working the moment
   the patches apply. Pick a low-traffic window and notify each
   credential owner.

3. **Decide each credential's new scope + run allowlist.** v1.0.0
   replaces the implicit "all scopes" default with explicit
   per-credential scope selection (twelve verbs:
   `user:read/write`, `survey:read/write`, `run:read/write`,
   `session:read/write`, `data:read`, `file:read/write`) and an
   optional per-credential run allowlist. Default-deny, so anything
   you don't enumerate is forbidden.

## Apply

```bash
# inside the docker stack
./update.sh
# or
./update_formr.sh
```

Both wrappers source `migrate_sessions.sh`, run `./db_atlas_apply.sh
apply` against the new patch set, and recycle `formr_app` + the
daemons. The breaking patches (050, 051, 052) apply as part of that
flow.

## Rotate

For each API consumer identified in step 1:

1. As the credential's owning user, log in to `/admin/account#api`.
2. The credential row will show in the credential table with its
   label intact but no secret. Click **Rotate** on that row.
3. In the rotate form, select the scopes the consumer needs and the
   runs (if any) it should be limited to. Click **Rotate** to confirm.
4. Copy the new client_secret displayed — it is shown **only** at
   rotation time. Storage holds only a SHA-256 hash thereafter.
5. Update the consumer (cron, dashboard, R script) with the new
   secret. Mint a fresh access token via `client_credentials` and
   verify the consumer works.

If you don't see an existing credential row, the user is at
`admin = 1` and lost API access in v1.0.0. A SuperAdmin must promote
them to `admin = 2` via `/admin/advanced/user_management` before
they can rotate.

## Verify

```bash
# from any host with network reach to the API
curl -X POST https://<admin-domain>/api/oauth/access_token \
  -d 'grant_type=client_credentials' \
  -u '<new_client_id>:<new_client_secret>'
# expect: a JSON envelope with access_token, expires_in, scope
```

```bash
# use the returned access_token (Authorization header — query string
# and POST body are now rejected with 401):
curl https://<admin-domain>/api/v1/user/me \
  -H "Authorization: Bearer <new_access_token>"
# expect: 200 with the user profile
```

If the second call returns 403 with "Insufficient permissions:
'user:read' scope required", the rotated credential didn't include
`user:read` — rotate again and add it.

## Rollback

Atlas can revert the migrations on a dev host via:

```bash
./db_atlas_down.sh
```

This requires the dev-url Docker socket mount enabled by
`docker-compose-dev-remote.yml` or `docker-compose-local.yml`; prod
deployments have no automatic reversal path. If you need to roll
back in prod, restore from the nightly backup taken before the
deploy.

## What the upgrade does NOT change

- Existing `oauth_clients.user_id`, `oauth_clients.label`, and
  `oauth_client_runs` rows are preserved across the upgrade. Only
  the tokens and secrets are wiped.
- `formr-crypto.key` is unchanged. At-rest crypto for participant
  data is unaffected.
- Web-admin login (session cookies) is unchanged. Only API access
  is affected.
