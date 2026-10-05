// Alpine `fmrForm` component + `x-showif` directive.
//
// Replaces the hand-rolled showif evaluator from Phase 3's first iteration.
// `fmrForm` reactivises one top-level field per named input; `x-showif`
// runs the showif, JavaScript as the author wrote it, through Alpine's
// effect() so dep-tracking
// and re-evaluation are free. Server emits `data-showif`; the bundle
// promotes that to `x-showif` at init (see registerAttributePromotion).
//
// The directive is robustness-hardened in two places:
//   1. Strip `//` and `/* */` comments first — a trailing comment (v1's
//      `//js_only` marker, say) otherwise swallows our wrapping closing
//      paren and produces SyntaxError at `new AsyncFunction()` time.
//   2. Wrap runtime eval in `(function(){try{return (expr)}catch(e){return undefined}})()`
//      so ReferenceErrors on names the browser doesn't know (a run variable
//      like `ran_group`, another survey's item) silently fall back to
//      undefined (→ the item keeps its current visibility: hidden, as every
//      showif item renders) instead of noisily blowing up every keystroke.

import { readField, fieldKey, isItemHidden, showifControls } from '../lib/inputs.js';

// Names the `fmrForm` component itself owns.
//
// Item values and these helpers share ONE object — the showif
// references item names bare (`q1 == 2`), so they have to be top-level fields
// on the component. An item named `answered` (or `contains`, `last`, `isNA` —
// the name `is.na()` is rewritten into at :148) would therefore overwrite the
// method it shadows. The eval wrapper then swallows the resulting TypeError,
// the expression yields `undefined`, and the directive PRESERVES the current
// visibility — i.e. every showif on the page that calls that helper freezes at
// whatever the server rendered. So we simply never write a field whose key is
// one of ours: the study degrades to "this one item isn't reactive" instead.
// New uploads can't get into that state at all — the server rejects these as
// item names (`Item::RESERVED_NAMES`, application/Model/Item/Item.php).
//
// Alpine's own magics (`$el`, `$refs`, …) and internals (`_x_…`) need no entry:
// an item name must match `^[A-Za-z][A-Za-z0-9_]*$`, so it can never spell one.
export const RESERVED_FIELD_NAMES = new Set([
    'isNA', 'answered', 'contains', 'containsWord', 'startsWith', 'endsWith', 'last',
    'init', '_syncInput', '_emptyValue', '_onShowifToggled', '_seed',
]);

// The same coercion _syncInput applies to a live input: '' → null, numeric
// strings → numbers, so a seeded answer compares exactly like a typed one.
function coerceField(v) {
    if (Array.isArray(v)) return v.map(coerceField);
    if (v === '' || v === null || v === undefined) return null;
    const n = Number(v);
    return isNaN(n) ? v : n;
}

// Is a showif value missing? An array is missing when every entry is: an
// untouched geopoint reads [''] (its text box) and an untouched touch-mode
// rank ['', '', ''] (its rows), which coerceField makes [null] / [null, …].
export function isNA(v) {
    return v === null || v === undefined || v === ''
        || (Array.isArray(v) && v.every(isNA))
        || (typeof v === 'number' && isNaN(v));
}

// Was `needle` ticked (an array: an mc_multiple, a select_multiple), or is
// it in the text? Ticked choices compare as text, as the text branch does:
// the scope holds numeric choices as numbers, and the documented rewrite of
// v1's `x %contains% "2"` is `contains(x, "2")`.
export function contains(haystack, needle) {
    if (isNA(haystack)) return false;
    if (Array.isArray(haystack)) return haystack.some((h) => h === needle || String(h) === String(needle));
    return String(haystack).indexOf(String(needle)) > -1;
}

export function registerFmrForm(Alpine) {
    Alpine.data('fmrForm', () => ({
        isNA(v) { return isNA(v); },
        answered(v) { return !this.isNA(v); },
        contains(haystack, needle) { return contains(haystack, needle); },
        containsWord(haystack, word) {
            if (this.isNA(haystack)) return false;
            const re = new RegExp('\\b' + String(word).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b');
            return re.test(String(haystack));
        },
        startsWith(haystack, prefix) {
            if (this.isNA(haystack)) return false;
            return String(haystack).startsWith(String(prefix));
        },
        endsWith(haystack, suffix) {
            if (this.isNA(haystack)) return false;
            return String(haystack).endsWith(String(suffix));
        },
        last(arr) {
            return Array.isArray(arr) && arr.length > 0 ? arr[arr.length - 1] : arr;
        },

        init() {
            const inputs = this.$root.querySelectorAll('input[name], select[name], textarea[name]');
            inputs.forEach((inp) => {
                const raw = inp.name || '';
                if (raw.startsWith('_item_views')) return;
                const key = raw.endsWith('[]') ? raw.slice(0, -2) : raw;
                if (RESERVED_FIELD_NAMES.has(key)) return;
                if (!(key in this)) this[key] = null;
            });
            inputs.forEach((inp) => this._syncInput(inp));
            // The stored answers (FormRenderer::stateBlock) cover names no
            // input on the page owns (uploads, automatic items) so a
            // showif that reads one sees a value; a live input of the same
            // name always wins (the server renders the stored answer into it).
            const state = this.$root.querySelector('script.fmr-state');
            if (state) {
                try {
                    const items = (JSON.parse(state.textContent || '{}') || {}).items || {};
                    const values = {};
                    Object.keys(items).forEach((name) => { values[name] = items[name].value; });
                    this._seed(values, true);
                } catch (e) { /* malformed: skip */ }
            }
            this.$root.addEventListener('input', (e) => this._syncInput(e.target));
            this.$root.addEventListener('change', (e) => this._syncInput(e.target));
            // `x-showif` dispatches this from the item wrapper on every
            // visibility CHANGE; retract / restore the item's answer with it.
            this.$root.addEventListener('fmr:showif-toggled', (e) => this._onShowifToggled(e));
        },

        // Set fields from a name → value map the server supplied: saved answers
        // at load, the stored state after a page submit. `onlyUnknown` keeps
        // a field some input on the page already owns.
        _seed(values, onlyUnknown = false) {
            if (!values || typeof values !== 'object') return;
            Object.keys(values).forEach((key) => {
                if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(key) || RESERVED_FIELD_NAMES.has(key)) return;
                if (onlyUnknown && key in this) return;
                this[key] = coerceField(values[key]);
            });
        },

        // The "not answered" representation for one input, mirroring the shapes
        // _syncInput produces: `name[]` fields and multi-selects hold arrays,
        // everything else a scalar. Note a single Check_Item reads back as its
        // hidden partner's `0` when merely unchecked, but as `null` when hidden —
        // hidden means not submitted at all, which is what the server records.
        _emptyValue(inp) {
            const raw = inp.name || '';
            if (raw.endsWith('[]')) return [];
            if (inp.tagName === 'SELECT' && inp.multiple) return [];
            return null;
        },

        // A showif that hides an item RETRACTS its answer: collectPayload skips
        // the (now disabled) inputs and the server marks the item hidden, so the
        // reactive field has to read as unanswered too. Otherwise a cascaded
        // showif (`answered(q2)`, where q2 was itself hidden by a q1 change) and
        // the client-side required gate keep evaluating against a value that will
        // never be submitted.
        //
        // On re-show we re-read the DOM rather than clearing it: v1 semantics are
        // that the inputs keep what the participant typed, so the item comes back
        // pre-filled — and now that it is submitted again, the field must hold
        // that value once more.
        _onShowifToggled(e) {
            const wrapper = e.target;
            if (!wrapper || !wrapper.querySelectorAll) return;
            const visible = !!(e.detail && e.detail.visible);
            wrapper.querySelectorAll('input[name], select[name], textarea[name]').forEach((inp) => {
                const raw = inp.name || '';
                if (!raw || raw.startsWith('_item_views')) return;
                const key = fieldKey(raw).key;
                if (RESERVED_FIELD_NAMES.has(key)) return;
                if (visible) {
                    this._syncInput(inp);
                } else {
                    this[key] = this._emptyValue(inp);
                }
            });
        },

        _syncInput(inp) {
            // lib/inputs.js reads the input against its group (radio /
            // checkbox array / Check_Item partner) exactly as the submit
            // does; here the answer is also typed for arithmetic.
            const field = readField(inp, this.$root);
            if (!field || RESERVED_FIELD_NAMES.has(field.key)) return;
            this[field.key] = coerceField(field.value);
        },
    }));
}

// Strip `//` and `/* */` comments WITHOUT touching string literals. The old
// blind regexes turned `q1 == "http://x"` into a SyntaxError (everything after
// `//` vanished), which silently downgraded the showif to the static server
// decision. Single-pass scanner: inside ' " ` we only honour backslash
// escapes; outside we drop comment spans.
export function stripComments(src) {
    let out = '';
    let quote = null;
    for (let i = 0; i < src.length; i++) {
        const c = src[i];
        if (quote) {
            out += c;
            if (c === '\\' && i + 1 < src.length) { out += src[++i]; continue; }
            if (c === quote) quote = null;
            continue;
        }
        if (c === "'" || c === '"' || c === '`') { quote = c; out += c; continue; }
        if (c === '/' && src[i + 1] === '/') {
            while (i < src.length && src[i] !== '\n') i++;
            out += '\n';
            continue;
        }
        if (c === '/' && src[i + 1] === '*') {
            i += 2;
            while (i < src.length && !(src[i] === '*' && src[i + 1] === '/')) i++;
            i++; // skip the '/' (loop increment skips the '*')
            continue;
        }
        out += c;
    }
    return out;
}

export function registerXShowif(Alpine) {
    Alpine.directive('showif', (el, { expression }, { evaluateLater, effect }) => {
        const cleaned = stripComments(expression || '').trim();
        if (!cleaned) return;
        const safe = `(function(){try{return (${cleaned})}catch(e){return undefined}})()`;

        const applyVisibility = (visible) => {
            const changed = el.classList.contains('hidden') === !!visible;
            el.classList.toggle('hidden', !visible);
            el.toggleAttribute('data-fmr-hidden', !visible);
            el.style.display = visible ? '' : 'none';
            // `button` included: PWA prompt items (add_to_home_screen,
            // push_notification) put name+disabled on a <button> (server
            // hides pending-client items WITH disabled). Only what this
            // directive disabled comes back on reveal — the marker, set here
            // or by claimPendingShowifControls for the server's render — so a
            // button the item's own code disabled (can't push, already
            // installed) stays disabled.
            showifControls(el).forEach((inp) => {
                if (!visible) {
                    if (inp.disabled) return;   // already off: not ours to restore
                    inp.disabled = true;
                    inp.dataset.fmrShowifDisabled = '1';
                } else {
                    if (!inp.dataset.fmrShowifDisabled) return;
                    inp.disabled = false;
                    delete inp.dataset.fmrShowifDisabled;
                }
                // tom-select reads its original's `disabled` only at init
                // (items/tom-select.js runs before Alpine, while a showif item
                // is still rendered disabled) and stays locked otherwise: a
                // revealed select could not be opened.
                if (inp.tomselect) {
                    try { if (visible) inp.tomselect.enable(); else inp.tomselect.disable(); } catch (e) { /* noop */ }
                }
            });
            // Tell layout chrome (the solo step controller) that the step set
            // changed — it may need to re-seat off a now-hidden current step
            // and refresh its progress count.
            if (changed) {
                el.dispatchEvent(new CustomEvent('fmr:showif-toggled', {
                    bubbles: true,
                    detail: { visible: !!visible },
                }));
            }
        };

        let getValue;
        try {
            getValue = evaluateLater(safe);
        } catch {
            return; // unparseable: leave the server's render decision in place
        }
        effect(() => {
            getValue((result) => {
                // `undefined` = the expression couldn't be evaluated — it references
                // a name the client doesn't know (a run variable like `ran_group`;
                // the server never evaluates showifs). Match v1 (survey.js
                // defaults _hide=true): do NOT force-show. Every showif item
                // renders `.hidden` (Item::hideByDefaultPendingClient), so an
                // unknowable showif keeps its item hidden — or, once it was
                // shown, keeps the current state — instead of flashing visible.
                // The current state is the showif's own record (isItemHidden),
                // not the bare class: the admin preview's "Show hidden items"
                // removes the class for inspection without un-hiding the item.
                const visible = (result === undefined)
                    ? !isItemHidden(el)
                    : !!result;
                applyVisibility(visible);
            });
        });
    });
}

// Promote server-emitted `data-showif` to Alpine's `x-showif` directive +
// add `x-data="fmrForm"` on the root form. No server changes required.
export function promoteShowifAttributes(root) {
    root.querySelectorAll('[data-showif]').forEach((el) => {
        const expr = el.getAttribute('data-showif');
        if (expr) el.setAttribute('x-showif', expr);
        el.removeAttribute('data-showif');
    });
    if (!root.hasAttribute('x-data')) {
        root.setAttribute('x-data', 'fmrForm');
    }
}
