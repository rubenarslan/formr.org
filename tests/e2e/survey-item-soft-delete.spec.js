// Survey-item soft-delete (patch 069).
//
// Editing a study mid-collection to remove an item must NOT wipe the
// long-format (survey_items_display) participant data. The item flips to a
// `deleted` marker: the row + its answers are preserved and recoverable, the
// item leaves the live set, and re-adding the name revives the same row.
//
// Drives the real admin paths (add_survey to create, upload_items to edit) with
// the saved robot storageState, and asserts at the DB via the docker-exec
// helper. Hermetic: a throwaway study, cleaned up before and after.

const { test, expect, request } = require('@playwright/test');
const path = require('node:path');
const db = require('./helpers/db');

const ADMIN_URL = process.env.FORMR_DEV_URL || 'https://formr.researchmixtape.com';
const ADMIN_STATE = path.resolve(__dirname, 'setup/admin-state.json');
const ROBOT_ID = 5;
const STUDY = 'e2e_soft_delete';

const CSV_AB = 'type,name,label,optional\ntext,keep_me,Keep me,1\ntext,remove_me,Remove me,1\n';
const CSV_A = 'type,name,label,optional\ntext,keep_me,Keep me,1\n';

function studyRow() {
    return db.dbQuery(
        `SELECT id, results_table FROM survey_studies WHERE user_id = ${ROBOT_ID} AND name = '${STUDY}' ORDER BY id DESC LIMIT 1`,
    )[0];
}
function cleanup() {
    const s = studyRow();
    if (!s) return;
    const id = parseInt(s.id, 10);
    const rt = /^[a-zA-Z0-9_]+$/.test(s.results_table || '') ? s.results_table : null;
    db.dbExecRaw(
        `DELETE FROM survey_items_display WHERE item_id IN (SELECT id FROM survey_items WHERE study_id = ${id});\n`
        + `DELETE FROM survey_items WHERE study_id = ${id};\n`
        + `DELETE FROM survey_units WHERE id = ${id};\n`
        + `DELETE FROM survey_studies WHERE id = ${id};\n`
        + (rt ? `DROP TABLE IF EXISTS \`${rt}\`;\n` : ''),
    );
}
async function upload(ctx, url, buffer, fields = {}) {
    return ctx.post(url, {
        multipart: Object.assign({}, fields, {
            uploaded: { name: `${STUDY}.csv`, mimeType: 'text/csv', buffer: Buffer.from(buffer) },
        }),
    });
}

test.describe('survey item soft-delete', () => {
    test.beforeAll(() => cleanup());
    test.afterAll(() => cleanup());

    test('removing an item soft-deletes it and preserves long-format data; re-adding revives it', async () => {
        const ctx = await request.newContext({ ignoreHTTPSErrors: true, storageState: ADMIN_STATE });

        // 1) create the study with keep_me + remove_me
        expect((await upload(ctx, `${ADMIN_URL}/admin/survey/add_survey/`, CSV_AB, { new_study: '1' })).ok()).toBeTruthy();
        const s = studyRow();
        expect(s, 'study should exist after create').toBeTruthy();
        const sid = parseInt(s.id, 10);
        const bid = parseInt(db.dbQuery(`SELECT id FROM survey_items WHERE study_id = ${sid} AND name = 'remove_me'`)[0].id, 10);

        // 2) simulate a participant answer for remove_me (borrow any valid unit session)
        const sess = parseInt(db.dbQuery('SELECT MAX(id) AS id FROM survey_unit_sessions')[0].id, 10);
        db.dbExecRaw(
            `INSERT INTO survey_items_display (item_id, session_id, answer, created, saved) VALUES (${bid}, ${sess}, 'preserved_42', NOW(), NOW());`,
        );

        // 3) edit: re-upload with keep_me only -> soft-delete remove_me
        expect((await upload(ctx, `${ADMIN_URL}/admin/survey/${STUDY}/upload_items/`, CSV_A)).ok()).toBeTruthy();

        const afterDel = db.dbQuery(`SELECT name, IF(deleted IS NULL,0,1) AS del FROM survey_items WHERE study_id = ${sid} ORDER BY name`);
        const byName = Object.fromEntries(afterDel.map((r) => [r.name, r.del]));
        expect(byName.remove_me, 'remove_me row kept + marked deleted').toBe('1');
        expect(byName.keep_me, 'keep_me stays live').toBe('0');
        const answer = db.dbQuery(`SELECT answer FROM survey_items_display WHERE item_id = ${bid} AND session_id = ${sess}`)[0];
        expect(answer && answer.answer, 'long-format answer preserved through delete').toBe('preserved_42');

        // 4) re-add remove_me -> revived (same row id, data intact)
        expect((await upload(ctx, `${ADMIN_URL}/admin/survey/${STUDY}/upload_items/`, CSV_AB)).ok()).toBeTruthy();
        const revived = db.dbQuery(`SELECT id, deleted FROM survey_items WHERE study_id = ${sid} AND name = 'remove_me'`)[0];
        expect(parseInt(revived.id, 10), 'same row id (data intact)').toBe(bid);
        expect(revived.deleted, 'deleted marker cleared on re-add').toBeFalsy();
        const stillThere = db.dbQuery(`SELECT answer FROM survey_items_display WHERE item_id = ${bid} AND session_id = ${sess}`)[0];
        expect(stillThere && stillThere.answer).toBe('preserved_42');

        await ctx.dispose();
    });
});
