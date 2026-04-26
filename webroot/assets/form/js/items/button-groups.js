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

            // Initial sync: visible button reflects input state.
            if (input.checked) btn.classList.add('btn-checked');

            btn.addEventListener('click', (e) => {
                e.preventDefault();
                if (isRadio) {
                    group.querySelectorAll('input[type=radio]').forEach((r) => { r.checked = false; });
                    group.querySelectorAll('.btn[data-for]').forEach((b) => b.classList.remove('btn-checked'));
                    input.checked = true;
                    btn.classList.add('btn-checked');
                } else {
                    input.checked = !input.checked;
                    btn.classList.toggle('btn-checked', input.checked);
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
                fb.textContent = input.validationMessage || 'Please choose an option.';
                const btnGroup = group.querySelector('.btn-group');
                if (btnGroup) btnGroup.insertAdjacentElement('afterend', fb);
                else group.appendChild(fb);
            });
            input.addEventListener('change', () => {
                group.querySelectorAll('.fmr-btn-feedback').forEach((el) => el.remove());
            });
        });
    });
}
