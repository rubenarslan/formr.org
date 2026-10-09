// Button-group items (mc_button / check_button / mc_multiple_button /
// rating_button). v1 used webshim's `webshim.cfg.forms` to keep the real
// inputs hidden + show pretty button equivalents. Without webshim we
// re-assert `.js_hidden { display:none !important }` in form.scss and wire
// click pairing here: each `.btn[data-for]` toggles the matching `<input>`,
// and radio groups clear sibling state imperatively (the browser only does
// auto-uncheck on user-initiated click events on the input itself).
//
// Validation feedback for hidden inputs lives in items/validation: the
// `invalid` event handler renders `.fmr-btn-feedback` next to the visible
// button group since native tooltips have nowhere to anchor on display:none.
import { t } from '../lib/i18n.js';

// The buttons show their inputs' state, to the eye (.btn-checked) and to
// assistive technology (aria-pressed): the inputs are display:none, out of
// the accessibility tree. Every input's change brings the buttons along, so
// a value set from outside (an R preset, the admin's fill) shows too.
const reflect = (group) => {
    group.querySelectorAll('.btn[data-for]').forEach((btn) => {
        const input = group.querySelector(`#${CSS.escape(btn.getAttribute('data-for') || '')}`);
        const on = !!(input && input.checked);
        btn.classList.toggle('btn-checked', on);
        btn.setAttribute('aria-pressed', String(on));
    });
};

export function initButtonGroups(root) {
    root.querySelectorAll('.form-group.btn-radio, .form-group.btn-checkbox, .form-group.btn-check').forEach((group) => {
        if (group.dataset.fmrButtonGroupInit === '1') return;
        group.dataset.fmrButtonGroupInit = '1';
        const isRadio = group.classList.contains('btn-radio');

        group.querySelectorAll('.btn[data-for]').forEach((btn) => {
            const inputId = btn.getAttribute('data-for');
            if (!inputId) return;
            const input = group.querySelector(`#${CSS.escape(inputId)}`);
            if (!input) return;

            btn.addEventListener('click', (e) => {
                e.preventDefault();
                if (isRadio) {
                    // Clicking the picked option of an OPTIONAL question takes
                    // the answer back (#377) — otherwise a participant who
                    // touched it once could never leave it unanswered again.
                    const deselect = input.checked && !input.required;
                    group.querySelectorAll('input[type=radio]').forEach((r) => { r.checked = false; });
                    if (!deselect) input.checked = true;
                } else {
                    input.checked = !input.checked;
                }
                input.dispatchEvent(new Event('change', { bubbles: true }));
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });

        // Native `invalid` event surfaces validationMessage near the visible
        // button group (the hidden input has nowhere to anchor a tooltip).
        group.querySelectorAll('input').forEach((input) => {
            input.addEventListener('invalid', (e) => {
                e.preventDefault();
                if (group.querySelector('.fmr-btn-feedback')) return;
                const fb = document.createElement('div');
                fb.className = 'invalid-feedback fmr-btn-feedback d-block';
                fb.textContent = input.validationMessage || t('validation.pick_option', 'Please pick an option to continue.');
                const btnGroup = group.querySelector('.btn-group');
                if (btnGroup) btnGroup.insertAdjacentElement('afterend', fb);
                else group.appendChild(fb);
            });
            input.addEventListener('change', () => {
                reflect(group);
                group.querySelectorAll('.fmr-btn-feedback').forEach((el) => el.remove());
            });
        });
        reflect(group);
    });
}
