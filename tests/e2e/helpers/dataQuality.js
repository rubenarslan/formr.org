// Shared by the data-quality specs: finding a participant's stored records
// (runs e2e-dq-v1 / e2e-dq-v2, the example survey on each engine) and reading
// them the way the R helpers do.

const db = require('./db');
const RUNS = require('../setup/runs.json');

// Letters only: what a person's keyboard, and the human-input helper, can type.
const token = () => 'tok' + Math.random().toString(36).replace(/[^a-z]/g, '').slice(0, 8);
const resultsTable = (engine) => db.studyByName(RUNS.dq[engine].study).results_table;
// The participant's row, found by the token typed into the open question.
const rowOf = (engine, tok) => db.dbQuery(
    `SELECT * FROM \`${resultsTable(engine)}\` WHERE weekend_text LIKE ${db.sqlStr('%' + tok + '%')} ORDER BY session_id DESC LIMIT 1`,
)[0] || null;
const record = (json) => (json ? JSON.parse(json) : null);

// dq_flags() from documentation/data_quality/dq_agent.R, behaviour part: the
// indicators summed over the pages' records, with the same thresholds.
function behaviourFlags(records) {
    const s = (k) => records.reduce((a, r) => a + (Number(r[k]) || 0), 0);
    const pd = s('pd'), ins = s('ins_nokey_ch'), tch = s('txt_ch'), kn = s('kn'), keys = s('keys'), mv = s('mv'), scr = s('scr');
    const kcv = Math.min(...records.map((r) => r.kcv).filter((x) => x >= 0), Infinity);
    const checks = {
        untr: s('untr') > 0,
        prog: s('prog') > 0,
        fld_nokey: s('fld_nokey') > 0,
        ins_nokey: ins >= 20 && ins > 0.5 * tch,
        keys_share: tch >= 20 && keys < 0.5 * tch,
        kfast: kn >= 10 && s('kfast') > 0.3 * kn,
        kdwf: kn >= 10 && s('kdwf') > 0.5 * keys,
        kcv: kcv < 0.15,
        klp: s('klp') >= 8 && s('kwc') > 0 && s('klp_ws') / s('klp') < 1.3 * s('kws') / s('kwc'),
        kcap: s('kcap_ns') + s('kcap_nosk') > 0,
        ins_na: s('ins_na') > 0,
        kcode0: s('kcode0') >= 3,
        pd_ctr: pd >= 3 && s('pd_ctr') > 0.5 * pd,
        pd_fewmv: pd >= 3 && s('pd_fewmv') > 0.8 * pd,
        pd_scr: pd >= 1 && s('pd_scr') === pd,
        pd_newview: pd >= 2 && s('pd_newview') > 0.3 * pd,
        mv_mz: mv >= 10 && s('mv_mz') > 0.5 * mv,
        mv_jump: mv > 0 && s('mv_jump') > 0.3 * mv,
        scr_nogest: scr >= 3 && s('scr_nogest') > 0.8 * scr,
        foc_nogest: s('foc_nogest') > 1,
        foc_prog: s('foc_prog') > 0,
        clk_nopt: s('clk_nopt') > 0,
        act: s('act_hid') + s('act_unf') > 0,
    };
    return Object.keys(checks).filter((k) => checks[k]);
}
// The checksum the record holds of navigator.userAgent (adler32 in data-quality.js).
function adler32(text) {
    let a = 1, b = 0;
    for (let i = 0; i < text.length; i++) { a = (a + text.charCodeAt(i)) % 65521; b = (b + a) % 65521; }
    return (b * 65536 + a).toString(16);
}

module.exports = { token, resultsTable, rowOf, record, adler32, behaviourFlags };
