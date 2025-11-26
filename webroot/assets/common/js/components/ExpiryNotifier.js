import $ from 'jquery';
import { bootstrap_modal } from '../main.js';

// Extend translation dictionaries (German and French)
window.formrTranslations = window.formrTranslations || {};
window.formrTranslations.de = window.formrTranslations.de || {};
window.formrTranslations.fr = window.formrTranslations.fr || {};

Object.assign(window.formrTranslations.de, {
    'Page expired': 'Session abgelaufen',
    'This page has become outdated. Please refresh.': 'Diese Seite ist veraltet. Bitte aktualisieren Sie die Seite.',
    'Reload': 'Neu laden'
});

Object.assign(window.formrTranslations.fr, {
    'Page expired': 'Session expirée',
    'This page has become outdated. Please refresh.': 'Cette page est obsolète. Veuillez rafraîchir.',
    'Reload': 'Recharger'
});

// Simple translation helper (same pattern used elsewhere)
function t(text) {
    if (window.formrLanguage && typeof window.formrLanguage.translate === 'function') {
        return window.formrLanguage.translate(text);
    }
    return text;
}

/**
 * Initialize Expiry Notifier.
 * Shows a modal when the current page/session has expired. Optionally auto-reloads.
 */
export function initializeExpiryNotifier() {
    const run = () => {
        // Only act if expiry information is present
        if (!window.unit_session_expires) {
            return;
        }

        // Parse expiry date (expects ISO 8601)
        let expiryDate;
        try {
            // Handle both ISO 8601 (with timezone) and legacy MySQL format (space instead of T)
            expiryDate = new Date(String(window.unit_session_expires).replace(' ', 'T'));
        } catch (e) {
            console.error('ExpiryNotifier: could not parse expiry date', window.unit_session_expires, e);
            return;
        }

        // Track last activity (mousemove, keydown, scroll, touch)
        let lastActivity = Date.now();
        const activityEvents = ['mousemove', 'keydown', 'scroll', 'touchstart'];
        activityEvents.forEach(evt => {
            window.addEventListener(evt, () => { lastActivity = Date.now(); }, { passive: true });
        });

        // Compute delay until expiry (minimum 0)
        const delay = Math.max(expiryDate.getTime() - Date.now(), 0);

        // Schedule check
        if(delay < 2147483647) { // 2147483647 is the max value for setTimeout
            setTimeout(handleExpiry, delay);
        }

        function handleExpiry() {
            const now = Date.now();
            const inactivityMs = now - lastActivity;
            const oneHourMs = 3600 * 1000;
            const hasSurveyForm = $('form.main_formr_survey').length > 0;

            if (!hasSurveyForm && inactivityMs > oneHourMs) {
                // Auto-reload silently
                console.log('ExpiryNotifier: auto-reloading page after expiry & long inactivity');
                window.location.reload(true);
                return;
            }

            // Otherwise show modal
            const $modal = bootstrap_modal(t('Page expired'), t('This page has become outdated. Please refresh.'), 'tpl-expired-modal');
            // Translate button label
            $modal.find('.reload-btn').html('<i class="fa fa-refresh"></i> ' + t('Reload'));
            // Attach click handler
            $modal.find('.reload-btn').on('click', function () {
                window.location.reload(true);
            });
        }
    };

    if (document.readyState === 'loading') {
        $(run);
    } else {
        run();
    }
} 