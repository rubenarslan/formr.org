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
    const scope = root || inp.form || document;
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
    } else {
        value = inp.value;
    }
    return { key, isArray: isArray || (inp.tagName === 'SELECT' && inp.multiple), value };
}

// Every answer within `scope` as a flat name → value map, in document order
// with $_POST semantics: array fields accumulate, scalar fields are
// last-wins, and an input that answers nothing adds no key. A radio or
// checkbox group is read once, through its first input.
export function collectAnswers(scope, root) {
    const out = {};
    const seenGroups = new Set();
    scope.querySelectorAll('input[name], select[name], textarea[name]').forEach((inp) => {
        if (inp.type === 'radio' || (inp.type === 'checkbox' && inp.name.endsWith('[]'))) {
            if (seenGroups.has(inp.name)) return;
            seenGroups.add(inp.name);
        }
        const field = readField(inp, root);
        if (!field || field.value === null) return;
        if (field.isArray) {
            out[field.key] = (out[field.key] || []).concat(field.value);
        } else {
            out[field.key] = field.value;
        }
    });
    return out;
}
