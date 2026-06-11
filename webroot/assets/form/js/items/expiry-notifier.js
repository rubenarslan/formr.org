// Expiry notifier (v2 port of webroot/assets/common/js/components/ExpiryNotifier.js).
//
// When `window.unit_session_expires` is populated and the wall clock crosses
// it, surface a "Page expired" modal so the participant doesn't keep filling
// stale data into a dead session. Reload-on-click; if there's no form on the
// page (e.g. participant got bounced to a thank-you / endpage) AND they've
// been idle for more than an hour, silently reload.
//
// Vanilla port. v1 used jQuery + bootstrap_modal; v2 has neither, so the
// modal is hand-built (a simple fixed-position overlay) and the activity
// listeners are bare addEventListener calls.

const FORM_SELECTOR = 'form.fmr-form-v2';
const ONE_HOUR_MS = 3600 * 1000;
// setTimeout's max signed 32-bit delay; longer waits silently fire immediately.
const MAX_TIMEOUT_MS = 2147483647;

const TRANSLATIONS = {
    de: {
        'Page expired': 'Session abgelaufen',
        'This page has become outdated. Please refresh.': 'Diese Seite ist veraltet. Bitte aktualisieren Sie die Seite.',
        'Reload': 'Neu laden',
    },
    fr: {
        'Page expired': 'Session expirée',
        'This page has become outdated. Please refresh.': 'Cette page est obsolète. Veuillez rafraîchir.',
        'Reload': 'Recharger',
    },
};

function t(text) {
    if (window.formrLanguage && typeof window.formrLanguage.translate === 'function') {
        return window.formrLanguage.translate(text);
    }
    const lang = (document.documentElement.lang || '').slice(0, 2).toLowerCase();
    return (TRANSLATIONS[lang] && TRANSLATIONS[lang][text]) || text;
}

function showExpiredModal() {
    if (document.querySelector('.fmr-expiry-modal')) return; // idempotent
    const overlay = document.createElement('div');
    overlay.className = 'fmr-expiry-modal';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'fmr-expiry-modal-title');
    overlay.innerHTML = `
        <div class="fmr-expiry-modal-backdrop"></div>
        <div class="fmr-expiry-modal-dialog">
            <h4 id="fmr-expiry-modal-title" class="fmr-expiry-modal-title">${t('Page expired')}</h4>
            <p class="fmr-expiry-modal-body">${t('This page has become outdated. Please refresh.')}</p>
            <button type="button" class="btn btn-primary fmr-expiry-modal-reload">
                <i class="fa fa-refresh"></i> ${t('Reload')}
            </button>
        </div>
    `;
    overlay.querySelector('.fmr-expiry-modal-reload').addEventListener('click', () => {
        window.location.reload();
    });
    document.body.appendChild(overlay);
}

export function initExpiryNotifier() {
    if (!window.unit_session_expires) return;

    let expiryDate;
    try {
        // Accept ISO 8601 (with timezone) and the legacy MySQL "YYYY-MM-DD HH:MM:SS"
        // format the v1 path also normalized.
        expiryDate = new Date(String(window.unit_session_expires).replace(' ', 'T'));
    } catch (e) {
        console.error('ExpiryNotifier: could not parse expiry date', window.unit_session_expires, e);
        return;
    }
    const expiryMs = expiryDate.getTime();
    if (!Number.isFinite(expiryMs)) return;

    let lastActivity = Date.now();
    const activityEvents = ['mousemove', 'keydown', 'scroll', 'touchstart'];
    activityEvents.forEach((evt) => {
        window.addEventListener(evt, () => { lastActivity = Date.now(); }, { passive: true });
    });

    const handleExpiry = () => {
        const inactivityMs = Date.now() - lastActivity;
        const hasForm = document.querySelector(FORM_SELECTOR);
        if (!hasForm && inactivityMs > ONE_HOUR_MS) {
            // Participant has long since wandered off and there's nothing
            // to lose — silent reload (matches v1's behaviour).
            window.location.reload();
            return;
        }
        showExpiredModal();
    };

    const delay = Math.max(expiryMs - Date.now(), 0);
    if (delay < MAX_TIMEOUT_MS) {
        setTimeout(handleExpiry, delay);
    }
}
