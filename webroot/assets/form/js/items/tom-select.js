// Tom-select wiring for v2.
//
// `<select>` elements: v1 auto-wired select2; v2 uses tom-select to keep
// the participant bundle jQuery-free. Large dropdowns get an in-control
// search input; small ones render as a styled click-to-open menu.
//
// `<input class="select2add">` elements: select_or_add_one /
// select_or_add_multiple items. Server emits a plain text input with the
// choice set in `data-select2add` (JSON [{id,text}, ...]). We translate
// that to a tom-select with `create: true` unless the wrapper opts out via
// `.network_select` / `.ratgeber_class` / `.cant_add_choice` (designed for
// pick-from-existing only — e.g. social network studies).

import TomSelect from 'tom-select';

// select_or_add_multiple joins its entries with ";", not v1's ",": a choice
// may contain a comma (a place, "Berlin, Germany"); a semicolon is refused
// at upload instead (SelectOrAddMultiple_Item::formV2Unsupported).
export const MULTIPLE_DELIMITER = ';';
export const splitMultiple = (raw) => String(raw).split(/[;\n]/).map((s) => s.trim()).filter(Boolean);

export function initTomSelects(root) {
    initPlainSelects(root);
    initSelectOrAdd(root);
}

function initPlainSelects(root) {
    root.querySelectorAll('select').forEach((sel) => {
        if (!sel.name || sel.name === '') return;
        if (sel.dataset.tomSelectInit === '1') return;
        sel.dataset.tomSelectInit = '1';
        const needsSearch = sel.options.length > 20 || sel.classList.contains('select2zone');
        try {
            new TomSelect(sel, {
                create: false,
                controlInput: needsSearch ? '<input>' : null,
                plugins: sel.multiple ? ['remove_button'] : [],
                maxOptions: 1000,
            });
        } catch (e) {
            console.warn('tom-select init failed for', sel.name, e);
        }
    });
}

function initSelectOrAdd(root) {
    root.querySelectorAll('input.select2add').forEach((inp) => {
        if (!inp.name || inp.dataset.tomSelectInit === '1') return;
        inp.dataset.tomSelectInit = '1';
        const options = parseChoices(inp);
        const multiple = inp.dataset.select2multiple === '1' || inp.dataset.select2multiple === 1;
        const maxItems = parseInt(inp.dataset.select2maximumSelectionSize || '0', 10);
        const maxLength = parseInt(inp.dataset.select2maximumInputLength || '0', 10);
        const wrapper = inp.closest('.form-group');
        const lockedToChoices = !!(wrapper && (
            wrapper.classList.contains('network_select') ||
            wrapper.classList.contains('ratgeber_class') ||
            wrapper.classList.contains('cant_add_choice')
        ));
        // Seed pre-existing values (back-nav / fill). A multiple answer is
        // its entries joined by MULTIPLE_DELIMITER (the input's value, so
        // what is posted and stored); "\n" is how the server joins an
        // array reply (SelectOrAddMultiple_Item::getReply).
        const existingRaw = inp.value || '';
        const items = existingRaw
            ? (multiple ? splitMultiple(existingRaw) : [existingRaw.trim()])
            : [];
        items.forEach((val) => {
            if (!options.some((o) => o.id === val)) options.push({ id: val, text: val });
        });
        try {
            new TomSelect(inp, {
                valueField: 'id',
                labelField: 'text',
                searchField: ['text'],
                options,
                items,
                create: !lockedToChoices,
                persist: false,
                openOnFocus: true, // focusing the control opens the dropdown (single AND multiple)
                maxItems: multiple ? (maxItems > 0 ? maxItems : null) : 1,
                delimiter: multiple ? MULTIPLE_DELIMITER : ',',
                // An entry with the delimiter in it would come back as two.
                createFilter: multiple ? (input) => !input.includes(MULTIPLE_DELIMITER) : null,
                plugins: multiple ? ['remove_button'] : [],
                maxOptions: 500,
                onInitialize() {
                    if (maxLength > 0) {
                        const ctrl = this.control_input;
                        if (ctrl) ctrl.setAttribute('maxlength', String(maxLength));
                    }
                },
            });
        } catch (e) {
            console.warn('tom-select init failed for select_or_add', inp.name, e);
        }
    });
}

// The choices as tom-select options. A list written in one cell arrives
// already split (SelectOrAddOne_Item::unpackChoices, at ";" in v2), so every
// entry is one choice, commas and all.
function parseChoices(inp) {
    const out = [];
    try {
        const raw = inp.dataset.select2add;
        if (raw) {
            const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
            parsed.forEach((opt) => {
                const choice = String(opt.id || '').trim();
                if (choice) out.push({ id: choice, text: choice });
            });
        }
    } catch (e) {
        console.warn('select_or_add: bad data-select2add JSON for', inp.name, e);
    }
    return out;
}
