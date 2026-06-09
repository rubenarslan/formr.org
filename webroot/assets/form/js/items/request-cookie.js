// RequestCookie item (functional cookie consent).
//
// Server (RequestCookie_Item) renders a `.request-cookie-wrapper` with a
// hidden `<input>`, a `<button.request-cookie>`, and a `.status-message`.
// When the participant has already granted functional consent, we mark the
// item answered; otherwise the click opens the consent dialog via the
// `showPreferences()` module export from vanilla-cookieconsent. It is NOT a
// global — it operates on the consent singleton that `common/js/cookieconsent.js`
// (imported by the form bundle entry, main.js) configured with run(). Webpack
// dedupes the lib so this import shares that same singleton.
import { showPreferences } from 'vanilla-cookieconsent';

const FUNCTIONAL_COOKIE_NAME = 'formrcookieconsent';

const hasFunctionalConsent = () => {
    const row = document.cookie.split('; ').find((r) => r.startsWith(`${FUNCTIONAL_COOKIE_NAME}=`));
    if (!row) return false;
    try {
        const val = decodeURIComponent(row.split('=')[1] || '');
        return val.indexOf('"necessary","functionality"') !== -1;
    } catch {
        return false;
    }
};

const markAnswered = (wrapper) => {
    const hidden = wrapper.querySelector('input');
    const status = wrapper.querySelector('.status-message');
    const btn = wrapper.querySelector('button.request-cookie');
    if (hidden) { hidden.value = 'consent_given'; hidden.setCustomValidity(''); }
    if (status) status.textContent = 'Functional cookies enabled. You can continue.';
    if (btn) {
        btn.disabled = true;
        btn.classList.remove('btn-primary');
        btn.classList.add('btn-success');
        btn.innerHTML = '<i class="fa fa-check"></i> Enabled';
    }
    wrapper.closest('.form-group')?.classList.add('formr_answered');
};

export function initRequestCookie(root) {
    root.querySelectorAll('.request-cookie-wrapper').forEach((wrapper) => {
        const hidden = wrapper.querySelector('input');
        const btn = wrapper.querySelector('button.request-cookie');
        const required = wrapper.closest('.form-group')?.classList.contains('required');
        if (required && hidden) {
            hidden.setCustomValidity('Please enable functional cookies to continue.');
        }
        if (hasFunctionalConsent()) {
            markAnswered(wrapper);
            return;
        }
        if (btn) {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                try { showPreferences(); }
                catch (err) { console.warn('cookieconsent showPreferences failed', err); }
            });
        }
        // Poll for consent granted in another tab / via the footer dialog.
        const poll = setInterval(() => {
            if (hasFunctionalConsent()) {
                markAnswered(wrapper);
                clearInterval(poll);
            }
        }, 1000);
    });
}
