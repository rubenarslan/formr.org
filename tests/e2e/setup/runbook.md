# E2E test setup runbook

One-time admin setup that produces six persistent test runs on the dev
instance, plus `tests/e2e/setup/runs.json` (committed). After this runs once,
`npm run test:e2e` exercises the runs without needing the admin UI again.

The runbook is written for a human or for the `ui-playwright-tester` agent.
The agent should follow it verbatim and emit the final `runs.json` payload
when done. Re-running is idempotent in spirit: if a study or run with the
target name already exists, **skip and verify**, do not duplicate.

## Inputs

- Dev URL: `https://formr.researchmixtape.com` (admin), `https://study.researchmixtape.com` (participant)
- Admin credentials: `cat /home/admin/formr-docker/.env.dev` (`FORMR_DEV_ADMIN_EMAIL`, `FORMR_DEV_ADMIN_PASSWORD`). **Never paste them into chat or commit them.**
- Three fixtures (Google Sheets):
    - `all_widgets`: `https://docs.google.com/spreadsheets/d/1vXJ8sbkh0p4pM5xNqOelRUmslcq2IHnY9o52RmQLKFw/`
    - `pwa_high`:    `https://docs.google.com/spreadsheets/d/1F60bSMCrwleqEoz5GW7H1CJMUB3MjbAvfdiV7pvz2Tw/`
    - `pwa_low`:     `https://docs.google.com/spreadsheets/d/1ZsEOFstOZB4Nl86OIYg4YnTtfnRLm56hbrNc95XA91Y/`

## Outputs

Six runs and one JSON file:

| Suite         | Variant | Study name (pick on import)   | Run name        |
| ------------- | ------- | ----------------------------- | --------------- |
| `all_widgets` | `v1`    | `e2e_all_widgets`             | `e2e-aw-v1`     |
| `all_widgets` | `v2`    | `e2e_all_widgets_v2`          | `e2e-aw-v2`     |
| `pwa_high`    | `v1`    | `e2e_pwa_high`                | `e2e-pwa-h-v1`  |
| `pwa_high`    | `v2`    | `e2e_pwa_high_v2`             | `e2e-pwa-h-v2`  |
| `pwa_low`     | `v1`    | `e2e_pwa_low`                 | `e2e-pwa-l-v1`  |
| `pwa_low`     | `v2`    | `e2e_pwa_low_v2`              | `e2e-pwa-l-v2`  |

**Naming conventions (formr-enforced, learned the hard way):**
- **Study names** allow underscores (`a-zA-Z0-9_`).
- **Run names** allow hyphens but NOT underscores. The validator regex
  rejects `_` in run names; use `-` instead. Pick names that match
  the table above so the spec files (which hard-depend on the names in
  `runs.json`) keep working.

## 0. Login

1. `browser_navigate` to `https://formr.researchmixtape.com/admin/account/login`.
2. **Dismiss the cookie-consent dialog first.** vanilla-cookieconsent obscures the form. Click `[data-cc="accept-necessary"]`.
3. Fill `email` + `password` from `.env.dev`. Submit.
4. Confirm landing on `/admin/run/`.

## 1. Import the three studies, twice each (six SurveyStudy rows)

For each `(suite, variant)` pair, do this once.

1. Go to `https://formr.researchmixtape.com/admin/survey/`.
2. Click **"Add a new survey"** (or open `/admin/survey/add_survey/` directly).
3. **"Import a Googlesheet"** box: paste the sheet URL for the suite into the textarea `name="google_sheet"`. Submit. The dev server can reach Google; this is the canonical path. **The sheet must be shared "anyone with the link → Viewer"** — without it, Google returns 401 and the import alerts "could not be downloaded" (this happens to apparent network failure, but the actual cause is the sheet's link-share setting).
4. The study name is auto-derived from the sheet/file source filename
   (`AdminSurveyController.php:106`). After import, the study appears in the list at `/admin/survey/`.
5. **Rename to the standard name** so the run-create step is unambiguous: open the study (`/admin/survey/<auto_name>/`), find the rename action, set `new_name` to the value from the table above (e.g. `e2e_all_widgets_v2`).
   - The PHP endpoint is `POST /admin/survey/<old_name>/rename` with `new_name=<value>` (see `AdminSurveyController::renameAction` ~line 414). Either the UI button or a direct admin POST works.
6. After both v1 and v2 imports of the same source, you have two studies (e.g. `e2e_all_widgets` and `e2e_all_widgets_v2`).

If the target name is already taken (you've previously run this), skip the
import + rename step for that pair.

## 2. Create the runs (six)

For each `(suite, variant)` pair:

1. Go to `https://formr.researchmixtape.com/admin/run/` and click **"Create a new run"** (or `/admin/run/add/`).
2. Set the run **name** to the table value (e.g. `e2e_aw_v2`). Title can match.
3. Submit. Land on the run editor (`/admin/run/<name>/`).
4. **Add the body unit:**
   - **For v1:** click **"Add Survey"**. From the dropdown/picker, choose the corresponding study (e.g. `e2e_all_widgets`).
   - **For v2:** click **"Add Form (form_v2, beta)"**. Choose the v2 study (e.g. `e2e_all_widgets_v2`). `Form::create` will set `survey_studies.rendering_mode='v2'` on the study automatically.
5. **Add a Stop unit** at the end (click **"Add Stop"**). This is load-bearing:
   - Without a Stop, the participant session dangles after the form ("Oops, creator forgot a Stop"), and v2 POST endpoints 409 because `getCurrentUnitSession()` filters ended sessions.
6. Verify the run page lists the body unit and Stop unit.

### 2a. Verify v2 rendering mode

After creating the v2 runs, sanity-check the rendering mode. Quick way:

```bash
docker exec formr_db sh -c \
    'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "SELECT id, name, rendering_mode FROM survey_studies WHERE name LIKE \"e2e_%\""'
```

All `_v2` studies should show `rendering_mode='v2'`. If any v2 study still
reads `'v1'`, force-flip it:

```bash
docker exec formr_db sh -c \
    'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" -e "UPDATE survey_studies SET rendering_mode=\"v2\" WHERE name=\"e2e_<study>_v2\""'
```

## 3. PWA setup for `pwa_high` and `pwa_low` runs (4 runs)

The `pwa_high` and `pwa_low` fixtures need the run-level PWA wiring so the
manifest endpoint, VAPID public key, and apple-touch-icons surface on the
participant page.

For each of `e2e_pwa_h_v1`, `e2e_pwa_h_v2`, `e2e_pwa_l_v1`, `e2e_pwa_l_v2`:

1. Open `/admin/run/<name>/settings` (or whichever tab the run editor exposes for **PWA / Push** settings).
2. Enable PWA install (toggle / "App installable").
3. Upload a placeholder icon (PNG, ≥512px square). The Appstinence run already has icons — copy via the admin UI if available, or upload any solid-color test PNG. Required so `apple-touch-icon-*` files exist on disk.
4. Enable **push notifications** (generates a VAPID key for the run if one doesn't exist).
5. Verify by hitting `https://study.researchmixtape.com/<run>/manifest` in a browser tab — should return a JSON object with `name`, `start_url`, `scope`, `icons[]`.

If the admin UI doesn't expose every setting on a single page, the PWA admin
slice lives under `/admin/run/<name>/pwa` (see `Run::getPwaIconPath` /
`Run::getVapidPublicKey` references in `templates/run/form_index.php`).

## 4. Emit `runs.json`

After all six runs verify, write **`tests/e2e/setup/runs.json`** with the
exact run names you used:

```json
{
  "all_widgets": {
    "v1": { "run": "e2e-aw-v1" },
    "v2": { "run": "e2e-aw-v2" }
  },
  "pwa_high": {
    "v1": { "run": "e2e-pwa-h-v1" },
    "v2": { "run": "e2e-pwa-h-v2" }
  },
  "pwa_low": {
    "v1": { "run": "e2e-pwa-l-v1" },
    "v2": { "run": "e2e-pwa-l-v2" }
  }
}
```

Commit it with the changeset that adds the runbook + specs.

## 5. Smoke verify

From the repo root:

```bash
cd formr_source
npm run test:e2e -- --grep "manifest endpoint"
```

If the four manifest checks pass, the runs are wired. Then run the full suite:

```bash
npm run test:e2e
```

Expect green for `all_widgets v1`, `all_widgets v2`, both `PWA … v1/v2`
local-chromium tests, and skips on the `[BS-only]` slices.

## Cleanup

Don't delete these runs after a session — they are persistent fixtures.
Other test runs will reuse them. If a run becomes corrupted (e.g. its
participant session gets stuck in a state the form can't render past), reset
sessions with the participant subdomain DELETE shortcut documented in the
top-level CLAUDE.md "form_v2 development notes" section, **not** by
deleting the run itself.
