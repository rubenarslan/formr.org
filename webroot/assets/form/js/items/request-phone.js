// RequestPhone item (mobile-device affirmation).
//
// Pared-down port of v1's initializeRequestPhone. The server already marks
// the item answered on mobile UAs (RequestPhone_Item::setMoreOptions sets
// no_user_input_required=true). On desktop we surface a short status
// message; full QR-code generation is deferred (part of the PWA wiring
// that still lives in PWAInstaller.js — see plan_form_v2.md §8 P1).

const MOBILE_UA_RE = /Mobi|Android|iPhone|iPad|iPod|BlackBerry|webOS|IEMobile|Opera Mini/i;

export function initRequestPhone(root) {
    const isMobile = MOBILE_UA_RE.test(navigator.userAgent);
    root.querySelectorAll('.request-phone-wrapper').forEach((wrapper) => {
        const hidden = wrapper.querySelector('input');
        const status = wrapper.querySelector('.status-message');
        const required = wrapper.closest('.form-group')?.classList.contains('required');
        if (required && hidden && !hidden.value) {
            hidden.setCustomValidity('Please complete this required step before continuing.');
        }
        if (isMobile) {
            if (hidden && !hidden.value) hidden.value = 'is_phone';
            if (hidden) hidden.setCustomValidity('');
            if (status) status.textContent = 'You are already on a mobile device. You can continue.';
            wrapper.closest('.form-group')?.classList.add('formr_answered');
        } else if (status) {
            status.textContent = 'Open this form on your phone to continue. (QR-code / install assistant lives in the v1 participant bundle; v2 port is pending — see plan_form_v2.md §8 P1.)';
        }
    });
}
