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

import { t, escapeHtml } from '../lib/i18n.js';

const FORM_SELECTOR = 'form.fmr-form-v2';
const ONE_HOUR_MS = 3600 * 1000;
// setTimeout's max signed 32-bit delay; longer waits silently fire immediately.
const MAX_TIMEOUT_MS = 2147483647;

// Throttle for the silent auto-reload, shared with v1's ExpiryNotifier (same
// localStorage key and window, so a v1 and a v2 tab of the origin share it).
// An idle PWA and a browser tab otherwise reload at the same moment and race
// for the run-session lock (the AMOR 2026-05-09 duplicate Email + Push
// deliveries). RunSession::execute's reload after taking the lock is the
// primary guard; this is belt-and-braces on the client.
const RELOAD_THROTTLE_KEY = 'expiryNotifierLastReloadAt';
const RELOAD_THROTTLE_MS = 30 * 1000;

function shouldThrottleReload(now) {
    try {
        const last = parseInt(localStorage.getItem(RELOAD_THROTTLE_KEY) || '0', 10);
        return last > 0 && (now - last) < RELOAD_THROTTLE_MS;
    } catch (_) {
        return false;
    }
}

function recordReload(now) {
    try {
        localStorage.setItem(RELOAD_THROTTLE_KEY, String(now));
    } catch (_) { /* private mode / quota */ }
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
    let lastActivity = Date.now();
    const activityEvents = ['mousemove', 'keydown', 'scroll', 'touchstart'];
    activityEvents.forEach((evt) => {
        window.addEventListener(evt, () => { lastActivity = Date.now(); }, { passive: true });
    });

    const handleExpiry = () => {
        const now = Date.now();
        const inactivityMs = now - lastActivity;
        const hasForm = document.querySelector(FORM_SELECTOR);
        if (!hasForm && inactivityMs > ONE_HOUR_MS) {
            // Participant has long since wandered off and there's nothing
            // to lose — silent reload (matches v1's behaviour).
            if (shouldThrottleReload(now)) return;
            recordReload(now);
            window.location.reload();
            return;
        }
        showExpiredModal();
    };

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
        if (delay < MAX_TIMEOUT_MS) timer = setTimeout(handleExpiry, delay);
    };
    armExpiry(window.unit_session_expires);
}
