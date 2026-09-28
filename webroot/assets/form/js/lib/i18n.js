// Engine-chrome i18n for the form_v2 bundle. The server injects
// window.fmrStrings (FormStrings::table resolved for the study's language,
// English fallback key-by-key; see templates/run/form_index.php). This helper
// reads it with a hardcoded-English fallback so the bundle keeps working on
// pages that predate the injection (cached HTML, tests).
//
//   t('save.saved', 'Saved')
//   t('queue.syncing', 'Syncing %n queued submission(s)…', { n: 3 })
//
// BUNDLED_STRINGS carries keys the bundle needs that an older server (one
// whose FormStrings.php predates them) may not inject. Resolution order:
// server table → bundled table for <html lang> → bundled English → the
// call-site fallback. Keep it in sync with application/FormStrings.php when
// adding keys; the server table always wins when it has the key.
const BUNDLED_STRINGS = {
    en: {
        'queue.needs_attention': 'Your answers for page %page could not be saved: please fix the highlighted answers and submit the page again.',
        'queue.needs_attention_other_run': 'A queued submission for another study on this device was rejected. Open that study to fix it.',
        'queue.needs_attention_stale': 'A page from an earlier visit to this study could not be saved and can no longer be sent.',
        'queue.waiting_first': 'An earlier page is still waiting to be sent. This page can be submitted once it has gone.',
        'queue.go_to_page': 'Go to page %page',
        'queue.retry': 'Retry now',
        'queue.blocked_session': '%n queued submission(s) could not be sent because your session has expired. Open your personal study link to sign in again, then retry.',
        'queue.blocked': '%n queued submission(s) could not be sent after several attempts. Your answers are kept on this device — please retry, or contact the study team if this persists.',
        'queue.retry_later': '%n queued submission(s) could not be sent yet. We\'ll retry automatically.',
        'queue.retrying': 'Retrying…',
    },
    de: {
        'queue.needs_attention': 'Ihre Antworten auf Seite %page konnten nicht gespeichert werden: Bitte korrigieren Sie die markierten Antworten und senden Sie die Seite erneut ab.',
        'queue.needs_attention_other_run': 'Eine zwischengespeicherte Antwort für eine andere Studie auf diesem Gerät wurde abgelehnt. Öffnen Sie diese Studie, um sie zu korrigieren.',
        'queue.needs_attention_stale': 'Eine Seite von einem früheren Besuch dieser Studie konnte nicht gespeichert werden und kann nicht mehr übertragen werden.',
        'queue.waiting_first': 'Eine frühere Seite wartet noch auf die Übertragung. Diese Seite kann abgeschickt werden, sobald sie übertragen ist.',
        'queue.go_to_page': 'Zu Seite %page',
        'queue.retry': 'Jetzt erneut versuchen',
        'queue.blocked_session': '%n zwischengespeicherte Antwort(en) konnten nicht übertragen werden, da Ihre Sitzung abgelaufen ist. Öffnen Sie Ihren persönlichen Studienlink, um sich erneut anzumelden, und versuchen Sie es dann erneut.',
        'queue.blocked': '%n zwischengespeicherte Antwort(en) konnten auch nach mehreren Versuchen nicht übertragen werden. Ihre Antworten bleiben auf diesem Gerät gespeichert – bitte versuchen Sie es erneut oder wenden Sie sich an das Studienteam.',
        'queue.retry_later': '%n zwischengespeicherte Antwort(en) konnten noch nicht übertragen werden. Wir versuchen es automatisch erneut.',
        'queue.retrying': 'Erneuter Versuch…',
    },
};

function bundledLookup(key) {
    if (typeof document === 'undefined') return undefined;
    const lang = ((document.documentElement && document.documentElement.lang) || 'en').toLowerCase().split('-')[0];
    const table = BUNDLED_STRINGS[lang];
    if (table && Object.prototype.hasOwnProperty.call(table, key)) return table[key];
    if (Object.prototype.hasOwnProperty.call(BUNDLED_STRINGS.en, key)) return BUNDLED_STRINGS.en[key];
    return undefined;
}

export function t(key, fallback, params) {
    const table = (typeof window !== 'undefined' && window.fmrStrings) || {};
    let s = Object.prototype.hasOwnProperty.call(table, key) ? table[key] : bundledLookup(key);
    if (s == null) s = fallback != null ? fallback : key;
    if (params) {
        Object.keys(params).forEach((token) => {
            s = s.split('%' + token).join(String(params[token]));
        });
    }
    return s;
}
