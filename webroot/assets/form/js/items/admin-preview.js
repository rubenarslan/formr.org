// Admin preview "monkey bar" — sibling element rendered by Run::exec when
// admin is previewing the run. v1 wired these through jQuery+FormMonkey+
// select2; v2 provides a light vanilla port: enough to eyeball a form, not
// a full parity reimplementation.
//
// Bar lives outside `.fmr-form-v2` so we query against document, not root.

export function initAdminPreview({ root, getCurrentPage }) {
    const monkeyBar = document.querySelector('.monkey_bar');
    if (!monkeyBar) return;

    // "Show hidden items" — un-hide showif-hidden form-groups for inspection.
    const showHiddenBtn = monkeyBar.querySelector('.show_hidden_items');
    if (showHiddenBtn && root.querySelector('.form-group.hidden, .form-group[style*="display: none"], .form-group[style*="display:none"]')) {
        showHiddenBtn.disabled = false;
        showHiddenBtn.addEventListener('click', (e) => {
            e.preventDefault();
            root.querySelectorAll('.form-group, .item').forEach((el) => {
                el.classList.remove('hidden');
                el.style.display = '';
                el.querySelectorAll('input, select, textarea').forEach((i) => {
                    if (i.disabled && i.dataset.fmrShowifDisabled !== '1') {
                        i.disabled = false;
                    }
                });
            });
        });
    }

    // "Show hidden debugging messages" — toggle `.hidden` off so OpenCPU
    // debug panels become visible.
    const showDebugBtn = monkeyBar.querySelector('.show_hidden_debugging_messages');
    if (showDebugBtn && document.querySelector('.hidden_debug_message')) {
        showDebugBtn.disabled = false;
        showDebugBtn.addEventListener('click', (e) => {
            e.preventDefault();
            document.querySelectorAll('.hidden_debug_message').forEach((el) => {
                el.classList.toggle('hidden');
                if (!el.classList.contains('hidden')) {
                    el.style.display = 'block';
                } else {
                    el.style.display = '';
                }
            });
        });
    }

    // "Monkey mode" — auto-fill every visible input on the current page.
    const monkeyBtn = monkeyBar.querySelector('button.monkey');
    if (monkeyBtn) {
        monkeyBtn.disabled = false;
        monkeyBtn.addEventListener('click', (e) => {
            e.preventDefault();
            fillPageWithMonkey(getCurrentPage());
        });
    }
}

function fillPageWithMonkey(page) {
    if (!page) return;
    const today = new Date();
    const dateStr = today.toISOString().slice(0, 10);
    const defaultsByType = {
        text: 'thank the formr monkey',
        textarea: 'thank the formr monkey\nmany times',
        email: 'formr_monkey@example.org',
        url: 'https://formrmonkey.example.org/',
        date: dateStr,
        month: dateStr.slice(0, 7),
        week: (() => {
            const d = today;
            const onejan = new Date(d.getFullYear(), 0, 1);
            const week = Math.ceil((((d - onejan) / 86400000) + onejan.getDay() + 1) / 7);
            return `${d.getFullYear()}-W${String(week).padStart(2, '0')}`;
        })(),
        yearmonth: dateStr.slice(0, 7),
        datetime: today.toISOString().slice(0, 16),
        'datetime-local': today.toISOString().slice(0, 16),
        time: '11:22',
        color: '#ff0000',
        number: 20,
        tel: '+441234567890',
    };
    const visibleItems = page.querySelectorAll(
        '.form-group.form-row:not(.hidden):not(.item-submit):not(.item-note):not(.item-block):not(.item-note_iframe)'
    );
    visibleItems.forEach((group) => {
        const realInputs = [...group.querySelectorAll('input[name], select[name], textarea[name]')]
            .filter((i) => i.name && !i.name.startsWith('_item_views') && !i.disabled);
        if (realInputs.length === 0) return;

        const radios = realInputs.filter((i) => i.type === 'radio');
        const checks = realInputs.filter((i) => i.type === 'checkbox');
        if (radios.length) {
            const r = radios[0];
            r.checked = true;
            r.dispatchEvent(new Event('change', { bubbles: true }));
            return;
        }
        if (checks.length) {
            checks.slice(0, 1).forEach((c) => {
                c.checked = true;
                c.dispatchEvent(new Event('change', { bubbles: true }));
            });
            return;
        }
        const select = realInputs.find((i) => i.tagName === 'SELECT');
        if (select) {
            if (select.tomselect) {
                const opts = Object.keys(select.tomselect.options || {});
                if (opts.length) select.tomselect.setValue(opts[0]);
            } else if (select.options.length) {
                select.selectedIndex = Math.max(1, 0);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            return;
        }
        const addable = realInputs.find((i) => i.classList.contains('select2add') && i.tomselect);
        if (addable) {
            const opts = Object.keys(addable.tomselect.options || {});
            if (opts.length) addable.tomselect.setValue(opts[0]);
            else if (addable.tomselect.addOption({ id: 'monkey', text: 'monkey' })) {
                addable.tomselect.setValue('monkey');
            }
            return;
        }
        const target = realInputs.find((i) => i.type !== 'hidden' && !i.readOnly);
        if (!target) return;
        const t = target.type || target.tagName.toLowerCase();
        let val = defaultsByType[t];
        if (t === 'range') {
            const min = Number(target.min || 0);
            const max = Number(target.max || 100);
            val = Math.round((min + max) / 2);
        }
        if (t === 'number') {
            const min = Number(target.min);
            const max = Number(target.max);
            if (!isNaN(min) && !isNaN(max)) val = Math.round((min + max) / 2);
        }
        if (val === undefined) val = defaultsByType.text;
        target.value = String(val);
        target.dispatchEvent(new Event('input', { bubbles: true }));
        target.dispatchEvent(new Event('change', { bubbles: true }));
    });
}
