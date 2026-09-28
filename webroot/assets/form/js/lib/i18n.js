// Engine-chrome i18n for the form_v2 bundle. The server injects
// window.fmrStrings (FormStrings::table resolved for the study's language,
// English fallback key-by-key; see templates/run/form_index.php) — the one
// table of these strings: a language is added in application/FormStrings.php.
// The call-site fallback keeps the bundle readable on a page without the
// injection (tests).
//
//   t('save.saved', 'Saved')
//   t('queue.syncing', 'Syncing %n queued submission(s)…', { n: 3 })

export function t(key, fallback, params) {
    const table = (typeof window !== 'undefined' && window.fmrStrings) || {};
    let s = Object.prototype.hasOwnProperty.call(table, key) ? table[key] : null;
    if (s == null) s = fallback != null ? fallback : key;
    if (params) {
        Object.keys(params).forEach((token) => {
            s = s.split('%' + token).join(String(params[token]));
        });
    }
    return s;
}

// For a translated string that goes into innerHTML as plain text. (Strings
// whose translation carries markup — <strong>, the troubleshooting lists —
// are inserted as-is: the table is ours, never participant data.)
export function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    })[c]);
}

// An icon + translated label for a button's innerHTML: the icon markup stays
// in the code, only the text comes from the table.
//   btn.innerHTML = iconLabel('fa-check', t('pwa.install.installed', 'Installed'))
export function iconLabel(iconClass, text) {
    return '<i class="fa ' + iconClass + '"></i> ' + escapeHtml(text);
}
