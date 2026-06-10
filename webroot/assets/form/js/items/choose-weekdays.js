// choose_two_weekdays: cap the selection at two. Nothing enforced this
// before — not even v1 — so a participant could check all seven boxes on a
// "choose two weekdays" item and the server stored them all. Revert the
// third check and explain inline, styled like the other client feedback.
export function initChooseWeekdays(root) {
    root.addEventListener('change', (e) => {
        const box = e.target;
        if (!box || box.type !== 'checkbox' || !box.classList.contains('choose2days')) return;
        const group = box.closest('.form-group');
        if (!group) return;

        const checkedCount = group.querySelectorAll('input.choose2days:checked').length;
        if (box.checked && checkedCount > 2) {
            box.checked = false;
            // Re-sync Alpine state / autosave with the reverted value. Safe:
            // re-entry hits the count<=2 branch below.
            box.dispatchEvent(new Event('change', { bubbles: true }));
            if (!group.querySelector('.fmr-weekdays-feedback')) {
                const fb = document.createElement('div');
                fb.className = 'invalid-feedback fmr-invalid-feedback d-block fmr-weekdays-feedback';
                fb.textContent = 'Please choose no more than two weekdays.';
                const anchor = group.querySelector('.controls') || group;
                anchor.appendChild(fb);
            }
            return;
        }
        if (checkedCount <= 2) {
            group.querySelector('.fmr-weekdays-feedback')?.remove();
        }
    });
}
