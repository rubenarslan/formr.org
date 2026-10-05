// Expiry notifier (v2 port of webroot/assets/common/js/components/ExpiryNotifier.js).
//
// When `window.unit_session_expires` is populated and the wall clock crosses
// it, surface a "Page expired" modal so the participant doesn't keep filling
// stale data into a dead session. Reload-on-click. (v1's silent reload of an
// idle page without a form has no v2 counterpart: the bundle runs only on a
// page with its form.)
//
// Vanilla port. v1 used jQuery + bootstrap_modal; v2 has neither, so the
// modal is hand-built (a simple fixed-position overlay).

import { t, escapeHtml } from '../lib/i18n.js';

// setTimeout's max signed 32-bit delay; longer waits silently fire immediately.
const MAX_TIMEOUT_MS = 2147483647;

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
            <h4 id="fmr-expiry-modal-title" class="fmr-expiry-modal-title">${escapeHtml(t('expiry.title', 'Page expired'))}</h4>
            <p class="fmr-expiry-modal-body">${escapeHtml(t('expiry.body', 'This page has become outdated. Please refresh.'))}</p>
            <button type="button" class="btn btn-primary fmr-expiry-modal-reload">
                <i class="fa fa-refresh"></i> ${escapeHtml(t('expiry.reload', 'Reload'))}
            </button>
        </div>
    `;
    overlay.querySelector('.fmr-expiry-modal-reload').addEventListener('click', () => {
        window.location.reload();
    });
    document.body.appendChild(overlay);
}

// The notice's timer, re-armed whenever the server names the deadline anew.
let armExpiry = null;

// A v2 form is one long-lived page whose deadline moves while it is open: an
// invitation window ends at the first answer, an inactivity window slides
// with each. Every write reply carries the deadline as it stands (`expires`,
// RunController::formDeadline); null means none.
export function updateExpiry(deadline) {
    if (armExpiry) armExpiry(deadline);
}

export function initExpiryNotifier() {
    let timer = null;
    armExpiry = (deadline) => {
        if (timer) { clearTimeout(timer); timer = null; }
        if (!deadline) return;
        // Accept ISO 8601 (with timezone) and the legacy MySQL
        // "YYYY-MM-DD HH:MM:SS" format the v1 path also normalized.
        const expiryMs = new Date(String(deadline).replace(' ', 'T')).getTime();
        if (!Number.isFinite(expiryMs)) {
            console.error('ExpiryNotifier: could not parse expiry date', deadline);
            return;
        }
        const delay = Math.max(expiryMs - Date.now(), 0);
        if (delay < MAX_TIMEOUT_MS) timer = setTimeout(showExpiredModal, delay);
    };
    armExpiry(window.unit_session_expires);
}
