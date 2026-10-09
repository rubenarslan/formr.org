// Studies and runs a spec builds for itself through the admin, and their
// removal: provisioner() keeps the record of what it made.
//
//   const prov = provisioner();
//   const study = await prov.uploadStudy(admin, { engine: 'v2', json });
//   const run = await prov.createRun(admin, name, [{ type: 'Survey', studyId: study.id }, { type: 'Page', body }]);
//   …
//   await prov.cleanup(admin);
//
// `admin` is a request context with the admin's cookies (helpers/admin.js).

const { ADMIN_BASE } = require('./admin');
const { dbQuery, dbExecRaw, sqlStr, studyByName } = require('./db');

const AJAX = { 'X-Requested-With': 'XMLHttpRequest' };

function alertText(html) {
    const m = String(html).match(/alert-danger[^>]*>([\s\S]{0,400})/);
    return m ? m[1].replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim() : '';
}

function provisioner() {
    const created = { studies: [], runs: [], units: [] };

    async function uploadStudy(admin, { engine, json }) {
        const res = await admin.post(`${ADMIN_BASE}/admin/survey/add_survey/`, {
            multipart: { engine, uploaded: { name: `${json.name}.json`, mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(json)) } },
        });
        const study = studyByName(json.name, 'id, name, results_table, rendering_mode, use_paging');
        if (!study) throw new Error(`add_survey ${json.name}: HTTP ${res.status()} ${alertText(await res.text())}`);
        created.studies.push(json.name);
        // The survey row exists even when its items were refused (and its
        // results table does not): say why here, not as a missing table in
        // the run.
        const items = Number(dbQuery(`SELECT COUNT(*) AS n FROM survey_items WHERE study_id = ${Number(study.id)}`)[0].n);
        if (!items) throw new Error(`add_survey ${json.name}: items not imported: ${alertText(await res.text())}`);
        if (study.rendering_mode !== engine) throw new Error(`${json.name} is on ${study.rendering_mode}, not ${engine}`);
        return study;
    }

    // units: [{ type: 'Survey', studyId } | { type: 'External', address, apiEnd } | { type: 'Page', body }]
    // placed at 10, 20, 30, …
    async function createRun(admin, name, units) {
        let res = await admin.post(`${ADMIN_BASE}/admin/run/add_run/`, { form: { run_name: name } });
        const run = dbQuery(`SELECT id FROM survey_runs WHERE name = ${sqlStr(name)}`)[0];
        if (!run) throw new Error(`add_run ${name}: HTTP ${res.status()} ${alertText(await res.text())}`);
        created.runs.push(name);
        const rid = Number(run.id);
        // add_run gives the run its special units (the service message page);
        // deleting the run unlinks them but leaves their unit rows.
        for (const su of dbQuery(`SELECT id FROM survey_run_special_units WHERE run_id = ${rid}`)) created.units.push(Number(su.id));
        for (const [i, u] of units.entries()) {
            const position = (i + 1) * 10;
            res = await admin.post(`${ADMIN_BASE}/admin/run/${name}/ajax_create_run_unit?type=${u.type}&position=${position}`, { headers: AJAX });
            if (!res.ok()) throw new Error(`${name}: create ${u.type} at ${position}: HTTP ${res.status()}`);
            const ru = dbQuery(`SELECT id, unit_id FROM survey_run_units WHERE run_id = ${rid} AND position = ${position}`)[0];
            // A Survey placement starts on a placeholder unit row that linking
            // the study leaves behind: remembered for cleanup.
            created.units.push(Number(ru.unit_id));
            const form = { unit_id: ru.unit_id, run_unit_id: ru.id, type: u.type, position };
            if (u.type === 'Survey') form.study_id = u.studyId;
            if (u.type === 'External') Object.assign(form, { external_link: u.address, api_end: u.apiEnd ? 1 : 0, expire_after: 0 });
            if (u.type === 'Page') Object.assign(form, { body: u.body, title: '' });
            res = await admin.post(`${ADMIN_BASE}/admin/run/${name}/ajax_save_run_unit`, { headers: AJAX, form });
            if (!res.ok()) throw new Error(`${name}: save ${u.type} at ${position}: HTTP ${res.status()}`);
        }
        dbExecRaw(`UPDATE survey_runs SET public = 2, expiresOn = DATE_ADD(NOW(), INTERVAL 1 YEAR) WHERE id = ${rid}`);
        const placed = dbQuery(`SELECT ru.position, ru.unit_id, u.type FROM survey_run_units ru JOIN survey_units u ON u.id = ru.unit_id `
            + `WHERE ru.run_id = ${rid} ORDER BY ru.position`);
        // Saving may have moved a placement onto another unit row (a Survey onto
        // its study, which cleanup leaves to delete_study).
        for (const p of placed) created.units.push(Number(p.unit_id));
        return { id: rid, units: placed.map((p) => ({ position: Number(p.position), unitId: Number(p.unit_id), type: p.type })) };
    }

    async function cleanup(admin) {
        for (const name of created.runs) {
            await admin.post(`${ADMIN_BASE}/admin/run/${name}/delete_run`, { form: { delete: '1', delete_confirm: name } }).catch(() => {});
        }
        for (const name of created.studies) {
            await admin.post(`${ADMIN_BASE}/admin/survey/${name}/delete_study`, { form: { delete_confirm: name } }).catch(() => {});
        }
        // External, Page, service-message and placeholder unit rows that no run
        // or study holds any more.
        const ids = [...new Set(created.units.filter((id) => Number.isInteger(id) && id > 0))];
        if (ids.length) {
            dbExecRaw(`DELETE FROM survey_units WHERE id IN (${ids.join(',')}) `
                + `AND NOT EXISTS (SELECT 1 FROM survey_studies s WHERE s.id = survey_units.id) `
                + `AND NOT EXISTS (SELECT 1 FROM survey_run_units ru WHERE ru.unit_id = survey_units.id) `
                + `AND NOT EXISTS (SELECT 1 FROM survey_run_special_units su WHERE su.id = survey_units.id);`);
        }
    }

    return { created, uploadStudy, createRun, cleanup };
}

module.exports = { provisioner, alertText, AJAX };
