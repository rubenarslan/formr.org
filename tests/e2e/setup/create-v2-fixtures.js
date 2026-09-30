#!/usr/bin/env node
// Provision the small form_v2 e2e fixtures: each JSON survey below becomes a
// study placed as a Form in its own run, followed by a Stop page.
//   v2_dynamic  e2e_v2_dynamic.json → run e2e-dyn-v2     (v2-dynamic.spec.js)
//   v2_inputs   e2e_v2_inputs.json  → run e2e-inputs-v2  (v2-inputs.spec.js)
//   all_widgets_v2  documentation/example_surveys/all_widgets_v2.json
//                   → run e2e-awv2    (v2-finish.spec.js)
// Idempotent: a fixture whose run already exists is skipped. Uses the robot
// admin session that global-setup writes, so run any e2e spec once first.
//
//   node tests/e2e/setup/create-v2-fixtures.js
const fs = require('node:fs');
const path = require('node:path');
const { request } = require('@playwright/test');
const db = require('../helpers/db');
const { ADMIN_BASE, STATE_PATH } = require('../helpers/admin');

const AJAX = { 'X-Requested-With': 'XMLHttpRequest' };
const FIXTURES = [
    { run: 'e2e-dyn-v2', study: 'e2e_v2_dynamic', json: 'e2e_v2_dynamic.json' },
    {
        run: 'e2e-inputs-v2', study: 'e2e_v2_inputs', json: 'e2e_v2_inputs.json',
        // #322: the study's own navigation wording
        after: (sid) => `UPDATE survey_studies SET next_button_label = 'Onward', submit_button_label = 'Finish now' WHERE id = ${sid}`,
    },
    // The committed example itself, not a copy: the walk-through test keeps
    // the showcase honest.
    { run: 'e2e-awv2', study: 'all_widgets_v2', json: '../../../documentation/example_surveys/all_widgets_v2.json' },
];

async function main() {
    for (const f of FIXTURES) {
        await provision(f);
    }
}

async function provision({ run: RUN, study: STUDY, json, after }) {
    const SURVEY = path.resolve(__dirname, json);
    if (db.dbQuery(`SELECT id FROM survey_runs WHERE name = '${RUN}'`).length) {
        console.log(`create-v2-fixtures: ${RUN} already exists — skipping.`);
        return;
    }
    const ctx = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
    try {
        let study = db.studyByName(STUDY);
        if (!study) {
            const res = await ctx.post(`${ADMIN_BASE}/admin/survey/add_survey/`, {
                // Engine v2: the survey's engine decides that its unit is a Form.
                multipart: { engine: 'v2', uploaded: { name: `${STUDY}.json`, mimeType: 'application/json', buffer: fs.readFileSync(SURVEY) } },
            });
            if (!res.ok()) throw new Error(`add_survey: HTTP ${res.status()}`);
            study = db.studyByName(STUDY);
            if (!study) throw new Error('survey upload did not create the study');
        }
        const sid = parseInt(study.id, 10);

        let res = await ctx.post(`${ADMIN_BASE}/admin/run/add_run/`, { form: { run_name: RUN } });
        if (!res.ok()) throw new Error(`add_run: HTTP ${res.status()}`);
        const run = db.dbQuery(`SELECT id FROM survey_runs WHERE name = '${RUN}'`)[0];
        if (!run) throw new Error('add_run did not create the run');
        const rid = parseInt(run.id, 10);

        res = await ctx.post(`${ADMIN_BASE}/admin/run/${RUN}/ajax_create_run_unit?type=Survey&position=10`, { headers: AJAX });
        if (!res.ok()) throw new Error(`create Form unit: HTTP ${res.status()}`);
        const form = db.dbQuery(`SELECT id AS run_unit_id, unit_id FROM survey_run_units WHERE run_id = ${rid} AND position = 10`)[0];
        res = await ctx.post(`${ADMIN_BASE}/admin/run/${RUN}/ajax_save_run_unit`, {
            headers: AJAX,
            form: { unit_id: form.unit_id, run_unit_id: form.run_unit_id, type: 'Survey', position: 10, study_id: sid },
        });
        if (!res.ok()) throw new Error(`link Form to study: HTTP ${res.status()}`);
        res = await ctx.post(`${ADMIN_BASE}/admin/run/${RUN}/ajax_create_run_unit?type=Page&position=20`, { headers: AJAX });
        if (!res.ok()) throw new Error(`create Stop unit: HTTP ${res.status()}`);

        db.dbExecRaw(`UPDATE survey_runs SET public = 2, expiresOn = DATE_ADD(NOW(), INTERVAL 5 YEAR) WHERE id = ${rid}`);
        if (after) db.dbExecRaw(after(sid));
        const linked = db.dbQuery(`SELECT ru.unit_id, u.type, s.rendering_mode FROM survey_run_units ru JOIN survey_units u ON u.id = ru.unit_id JOIN survey_studies s ON s.id = ru.unit_id WHERE ru.id = ${parseInt(form.run_unit_id, 10)}`)[0];
        if (!linked || parseInt(linked.unit_id, 10) !== sid || linked.type !== 'Form' || linked.rendering_mode !== 'v2') {
            throw new Error(`Form unit not linked as v2: ${JSON.stringify(linked)}`);
        }
        console.log(`create-v2-fixtures: run ${RUN} (id ${rid}) → Form → study ${STUDY} (id ${sid}), v2.`);
    } finally {
        await ctx.dispose();
    }
}

main().catch((e) => { console.error(e); process.exit(1); });
