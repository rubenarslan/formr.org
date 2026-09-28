// form_v2: the one reader of a form input's answer.
//
// Three consumers need "what does this input currently answer": the page
// submit (collectPayload), the answers overlaid on R (collectAllAnswers)
// and the showif scope (fmrForm._syncInput). They used to carry their own
// copies of the rules for `name[]` fields, unchecked choices, a Check_Item's
// hidden "0" partner and multi-selects; a drift meant a showif deciding on
// a value that was never submitted. This module holds the rules once; the
// consumers only differ in what they do with an empty answer.

// A field's key and shape from its input name: `name[]` is an array field.
export function fieldKey(rawName) {
    const raw = rawName || '';
    return raw.endsWith('[]') ? { key: raw.slice(0, -2), isArray: true } : { key: raw, isArray: false };
}

// Inputs that carry no answer: unnamed, the item-view timestamps, disabled
// (a showif-hidden item's inputs are disabled, so its answer is withdrawn).
export function isAnswerInput(inp) {
    return !!(inp && inp.name) && !inp.name.startsWith('_item_views') && !inp.disabled;
}

// The answer one input contributes, resolved against its group in `root`:
//   { key, isArray, value } — value is a string, an array of strings, or
//   null when the input answers nothing (an unchecked radio group, a
//   checkbox array with nothing ticked).
// A single Check_Item shares its name with a hidden "0" partner: unchecked
// it answers that partner's value ($_POST scalar-last-wins gives the server
// "0"), so a showif like `mycheck == 0` agrees with what is stored.
// File inputs are not answers (their bytes ride the multipart path); the
// caller decides what an empty optional file posts.
export function readField(inp, root) {
    if (!isAnswerInput(inp) || inp.type === 'file') return null;
    const { key, isArray } = fieldKey(inp.name);
    // A group (radios, a checkbox array, a check's hidden partner) lives in
    // its item's wrapper: searching that, not the whole form, keeps reading
    // every answer linear in the form's size.
    const scope = (inp.closest && inp.closest('.form-group')) || root || inp.form || document;
    const esc = (s) => (window.CSS && CSS.escape ? CSS.escape(s) : s.replace(/["\\]/g, '\\$&'));
    let value;
    if (inp.type === 'checkbox') {
        if (isArray) {
            value = Array.from(scope.querySelectorAll(`input[type=checkbox][name="${esc(inp.name)}"]:checked`))
                .filter((b) => !b.disabled).map((b) => b.value);
        } else if (inp.checked) {
            value = inp.value;
        } else {
            const partner = scope.querySelector(`input[type=hidden][name="${esc(inp.name)}"]`);
            value = partner && !partner.disabled ? partner.value : null;
        }
    } else if (inp.type === 'radio') {
        const checked = scope.querySelector(`input[type=radio][name="${esc(inp.name)}"]:checked`);
        value = checked && !checked.disabled ? checked.value : null;
    } else if (inp.tagName === 'SELECT' && inp.multiple) {
        value = Array.from(inp.selectedOptions).map((o) => o.value);
    } else if (isArray && inp.tagName !== 'SELECT') {
        // Several inputs sharing a `name[]` (a rank's rows) answer together,
        // in document order — one of them alone is not the answer.
        value = Array.from(scope.querySelectorAll(`input[name="${esc(inp.name)}"], textarea[name="${esc(inp.name)}"]`))
            .filter((el) => isAnswerInput(el) && el.type !== 'file')
            .map((el) => el.value);
    } else {
        value = inp.value;
    }
    return { key, isArray: isArray || (inp.tagName === 'SELECT' && inp.multiple), value };
}

// Every answer within `scope` as a flat name → value map, in document order
// with $_POST semantics: array fields accumulate, scalar fields are
// last-wins, and an input that answers nothing adds no key. A radio group
// or a `name[]` group (checkboxes, a rank's rows) is read once, through its
// first input — readField answers for the whole group.
export function collectAnswers(scope, root) {
    const out = {};
    const seenGroups = new Set();
    scope.querySelectorAll('input[name], select[name], textarea[name]').forEach((inp) => {
        if (inp.type === 'radio' || (inp.name.endsWith('[]') && inp.tagName !== 'SELECT')) {
            if (seenGroups.has(inp.name)) return;
            seenGroups.add(inp.name);
        }
        const field = readField(inp, root);
        if (!field || field.value === null) return;
        if (field.isArray) {
            // PHP: `x[]` after a scalar `x` replaces it with an array. A
            // geopoint renders a hidden `geo` (the fix as JSON) before its
            // visible `geo[]`; `(out.geo || []).concat(...)` on that string
            // was String#concat and posted '{"coords":…}lat:…/long:…'.
            const prev = Array.isArray(out[field.key]) ? out[field.key] : [];
            out[field.key] = prev.concat(field.value);
        } else {
            out[field.key] = field.value;
        }
    });
    return out;
}

// Is an item hidden? One definition for every consumer. The server renders
// every showif item with the `.hidden` class (so nothing flashes before
// Alpine runs), so a showif that never evaluates — leftover R, a syntax
// error — reads as hidden, as the participant sees it. Alpine's directive
// (showif/alpine.js applyVisibility) toggles the class and records its
// decision in `data-fmr-hidden`; the admin preview's "Show hidden items"
// removes only the class, so an item revealed for inspection still counts
// as hidden and a test submit records what the showif decided.
export function isItemHidden(wrapper) {
    return !!(wrapper && wrapper.classList
        && (wrapper.hasAttribute('data-fmr-hidden') || wrapper.classList.contains('hidden')));
}

// The ids of the showif items currently hidden within `scope`: what a page
// submit reports so the server records them as hidden (and its required
// check skips them). Only showif items are reported; the server honours no
// other claim.
export function hiddenShowifItemIds(scope) {
    return Array.from(scope.querySelectorAll('[data-showif], [x-showif]'))
        .filter(isItemHidden)
        .map((el) => {
            const v = el.querySelector('.item_shown');
            const m = v && v.name && v.name.match(/\[(\d+)\]/);
            return m ? Number(m[1]) : null;
        })
        .filter((id) => id != null);
}
