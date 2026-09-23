// Deselectable optional single-choice questions (#377).
//
// A native radio group can't be emptied again once something is picked, so a
// participant who touched an OPTIONAL question could no longer leave it
// unanswered. Clicking (or tapping) the picked option of an optional group
// takes the answer back. Required groups keep native behaviour. Button
// groups (mc_button, rating_button, …) hide their radios and handle this in
// items/button-groups.js.
//
// The browser has already checked the radio by the time `click` fires, so
// whether it WAS checked is recorded on pointerdown (a label click reaches the
// radio as a click too, so the label's pointerdown counts as well). Keyboard
// selection never fires pointerdown, so arrow keys and space behave natively.
//
// On a deselect the group posts its hidden "" partner (every mc renders one),
// which overwrites an answer autosave may already have stored.

const RADIO = 'input[type=radio]';

function radioFor(target) {
    if (!(target instanceof Element)) return null;
    if (target.matches(RADIO)) return target;
    const label = target.closest('label');
    if (!label) return null;
    return label.control && label.control.matches(RADIO) ? label.control : label.querySelector(RADIO);
}

function deselectable(radio) {
    return !!radio && !radio.required && !radio.disabled && !radio.closest('.btn-radio');
}

export function initRadioDeselect(root) {
    if (root.dataset.fmrRadioDeselectInit === '1') return;
    root.dataset.fmrRadioDeselectInit = '1';
    let armed = null;
    root.addEventListener('pointerdown', (e) => {
        const radio = radioFor(e.target);
        armed = (deselectable(radio) && radio.checked) ? radio : null;
    });
    root.addEventListener('click', (e) => {
        const radio = e.target instanceof Element && e.target.matches(RADIO) ? e.target : null;
        if (!radio || radio !== armed) return;
        armed = null;
        radio.checked = false;
        radio.dispatchEvent(new Event('input', { bubbles: true }));
        radio.dispatchEvent(new Event('change', { bubbles: true }));
    });
}
