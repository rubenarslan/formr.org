#!/usr/bin/env node
// Provision the small form_v2 e2e fixtures: each JSON survey below becomes a
// study placed as a Form in its own run, followed by a Stop page.
//   v2_dynamic  e2e_v2_dynamic.json → run e2e-dyn-v2     (v2-dynamic.spec.js)
//   v2_inputs   e2e_v2_inputs.json  → run e2e-inputs-v2  (v2-inputs.spec.js)
//   v2_hidden   e2e_v2_hidden.json  → run e2e-hidden-v2  (v2-hidden-pages.spec.js)
//   all_widgets_v2  documentation/example_surveys/all_widgets_v2.json
//                   → run e2e-awv2    (v2-finish.spec.js)
//   dq              documentation/example_surveys/data_quality.xlsx, once on
//                   each engine → runs e2e-dq-v1 and e2e-dq-v2
//                   (data-quality.spec.js); `engine: 'v1'` makes the unit a
//                   classic Survey.
//   dq_skip         e2e_dq_skip.csv, once on each engine → runs
//                   e2e-dqskip-v1 and e2e-dqskip-v2: a middle page whose one
//                   question has a showif, beside an agent_probe and a
//                   data_quality item (the showif is valid R and valid JS).
// Idempotent: a fixture whose run already exists is skipped. Uses the robot
// admin session that global-setup writes, so run any e2e spec once first.
//
//   node tests/e2e/setup/create-v2-fixtures.js
//
// With --refresh, a fixture whose run exists gets its survey file again, so a
// changed example survey reaches the dev instance. A spreadsheet goes through
// the survey's own "upload items". The admin takes a JSON survey only for a
// new survey; a run import does take one for a survey that exists (it uploads
// the items into the same-named survey), so a JSON fixture is imported into a
// scratch run, which is then deleted — a run's surveys outlive it.
//
//   node tests/e2e/setup/create-v2-fixtures.js --refresh
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
    {
        run: 'e2e-hidden-v2', study: 'e2e_v2_hidden', json: 'e2e_v2_hidden.json',
        // Previous: the spec changes an earlier answer.
        after: (sid) => `UPDATE survey_studies SET allow_previous = 1 WHERE id = ${sid}`,
    },
    // The committed example itself, not a copy: the walk-through test keeps
    // the showcase honest.
    { run: 'e2e-awv2', study: 'all_widgets_v2', json: '../../../documentation/example_surveys/all_widgets_v2.json' },
    { run: 'e2e-dq-v1', study: 'e2e_dq_v1', json: '../../../documentation/example_surveys/data_quality.xlsx', engine: 'v1' },
    {
        run: 'e2e-dq-v2', study: 'e2e_dq_v2', json: '../../../documentation/example_surveys/data_quality.xlsx',
        // Previous: the spec goes back to a page whose record is stored.
        after: (sid) => `UPDATE survey_studies SET allow_previous = 1 WHERE id = ${sid}`,
    },
    { run: 'e2e-dqskip-v1', study: 'e2e_dq_skip_v1', json: 'e2e_dq_skip.csv', engine: 'v1' },
    { run: 'e2e-dqskip-v2', study: 'e2e_dq_skip_v2', json: 'e2e_dq_skip.csv' },
];
const MIME = {
    '.csv': 'text/csv',
    '.json': 'application/json',
    '.xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
};

const REFRESH = process.argv.includes('--refresh');

async function main() {
    for (const f of FIXTURES) {
        await provision(f);
    }
}

// Give the fixture's existing study its survey file again.
async function refresh({ run: RUN, study: STUDY, json, engine = 'v2' }) {
    const SURVEY = path.resolve(__dirname, json);
    const ext = path.extname(SURVEY);
    const study = db.studyByName(STUDY);
    if (!study) {
        console.log(`create-v2-fixtures: ${RUN} already exists — skipping.`);
        return;
    }
    const sid = parseInt(study.id, 10);
    const items = () => db.dbQuery(`SELECT COUNT(*) AS n FROM survey_items WHERE study_id = ${sid} AND deleted IS NULL`)[0].n;
    const ctx = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
    try {
        const before = items();
        if (ext === '.json') {
            await importIntoScratchRun(ctx, STUDY, JSON.parse(fs.readFileSync(SURVEY, 'utf8')), engine);
        } else {
            const res = await ctx.post(`${ADMIN_BASE}/admin/survey/${STUDY}/upload_items`, {
                multipart: { uploaded: { name: `${STUDY}${ext}`, mimeType: MIME[ext], buffer: fs.readFileSync(SURVEY) } },
            });
            if (!res.ok()) throw new Error(`upload_items ${STUDY}: HTTP ${res.status()}`);
        }
        const still = db.studyByName(STUDY);
        if (!still || parseInt(still.id, 10) !== sid) throw new Error(`${STUDY}: the study is not the one it was (id ${sid})`);
        console.log(`create-v2-fixtures: ${RUN} — ${STUDY} uploaded again (${before} → ${items()} items).`);
    } finally {
        await ctx.dispose();
    }
}

// A run import uploads a unit's survey_data into the same-named survey of the
// importing user. The scratch run it needs is deleted again, whatever happens.
async function importIntoScratchRun(ctx, STUDY, survey, engine) {
    if (survey.name !== STUDY) throw new Error(`${STUDY}: the JSON names another survey ("${survey.name}")`);
    const scratch = `e2e-refresh-${Date.now().toString(36)}`;
    let res = await ctx.post(`${ADMIN_BASE}/admin/run/add_run/`, { form: { run_name: scratch } });
    if (!res.ok() || !db.dbQuery(`SELECT id FROM survey_runs WHERE name = '${scratch}'`).length) throw new Error(`add_run ${scratch}: HTTP ${res.status()}`);
    try {
        const bundle = { name: scratch, units: [{ type: engine === 'v2' ? 'Form' : 'Survey', description: STUDY, position: 10, special: null, survey_data: survey }] };
        res = await ctx.post(`${ADMIN_BASE}/admin/run/${scratch}/import`, {
            multipart: { run_file: { name: `${scratch}.json`, mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(bundle)) } },
        });
        if (!res.ok()) throw new Error(`import into ${scratch}: HTTP ${res.status()}`);
    } finally {
        res = await ctx.post(`${ADMIN_BASE}/admin/run/${scratch}/delete_run/`, { form: { delete: 'true', delete_confirm: scratch } });
        if (db.dbQuery(`SELECT id FROM survey_runs WHERE name = '${scratch}'`).length) {
            console.error(`create-v2-fixtures: could not delete the scratch run ${scratch} (HTTP ${res.status()}) — delete it by hand.`);
        }
    }
}

async function provision({ run: RUN, study: STUDY, json, after, engine = 'v2' }) {
    const SURVEY = path.resolve(__dirname, json);
    const ext = path.extname(SURVEY);
    if (db.dbQuery(`SELECT id FROM survey_runs WHERE name = '${RUN}'`).length) {
        if (REFRESH) return refresh({ run: RUN, study: STUDY, json, engine });
        console.log(`create-v2-fixtures: ${RUN} already exists — skipping.`);
        return;
    }
    const ctx = await request.newContext({ ignoreHTTPSErrors: true, storageState: STATE_PATH });
    try {
        let study = db.studyByName(STUDY);
        if (!study) {
            const res = await ctx.post(`${ADMIN_BASE}/admin/survey/add_survey/`, {
                // The survey's engine decides whether its unit is a Form (v2) or a Survey.
                multipart: { engine, uploaded: { name: `${STUDY}${ext}`, mimeType: MIME[ext], buffer: fs.readFileSync(SURVEY) } },
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
        const unitType = engine === 'v2' ? 'Form' : 'Survey';
        if (!linked || parseInt(linked.unit_id, 10) !== sid || linked.type !== unitType || (linked.rendering_mode === 'v2') !== (engine === 'v2')) {
            throw new Error(`${unitType} unit not linked as ${engine}: ${JSON.stringify(linked)}`);
        }
        console.log(`create-v2-fixtures: run ${RUN} (id ${rid}) → ${unitType} → study ${STUDY} (id ${sid}), ${engine}.`);
    } finally {
        await ctx.dispose();
    }
}

main().catch((e) => { console.error(e); process.exit(1); });
