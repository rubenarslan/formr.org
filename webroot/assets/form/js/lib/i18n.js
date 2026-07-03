// Engine-chrome i18n for the form_v2 bundle. The server injects
// window.fmrStrings (FormStrings::table resolved for the study's language,
// English fallback key-by-key; see templates/run/form_index.php). This helper
// reads it with a hardcoded-English fallback so the bundle keeps working on
// pages that predate the injection (cached HTML, tests).
//
//   t('save.saved', 'Saved')
//   t('queue.syncing', 'Syncing %n queued submission(s)…', { n: 3 })
export function t(key, fallback, params) {
    const table = (typeof window !== 'undefined' && window.fmrStrings) || {};
    let s = Object.prototype.hasOwnProperty.call(table, key) ? table[key] : fallback;
    if (s == null) s = fallback != null ? fallback : key;
    if (params) {
        Object.keys(params).forEach((token) => {
            s = s.split('%' + token).join(String(params[token]));
        });
    }
    return s;
}
