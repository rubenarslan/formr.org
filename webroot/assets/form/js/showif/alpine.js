// Alpine `fmrForm` component + `x-showif` directive.
//
// Replaces the hand-rolled showif evaluator from Phase 3's first iteration.
// `fmrForm` reactivises one top-level field per named input; `x-showif`
// runs transpiled JS expressions through Alpine's effect() so dep-tracking
// and re-evaluation are free. Server emits `data-showif`; the bundle
// promotes that to `x-showif` at init (see registerAttributePromotion).
//
// The directive is robustness-hardened in three places:
//   1. Strip `//` and `/* */` comments first — v1's `//js_only` marker
//      otherwise swallows our wrapping closing paren and produces
//      SyntaxError at `new AsyncFunction()` time.
//   2. Rewrite `(typeof(X) === 'undefined')` → `isNA(X)` because our
//      reactive state normalizes empty/unchecked to `null`, not `undefined`.
//   3. Wrap runtime eval in `(function(){try{return (expr)}catch(e){return undefined}})()`
//      so ReferenceErrors on run-level (`ran_group`) or future-page (`puppy`)
//      variables silently fall back to undefined (→ visible) instead of
//      noisily blowing up every keystroke.

export function registerFmrForm(Alpine) {
    Alpine.data('fmrForm', () => ({
        isNA(v) {
            return v === null || v === undefined || v === ''
                || (Array.isArray(v) && v.length === 0)
                || (typeof v === 'number' && isNaN(v));
        },
        answered(v) { return !this.isNA(v); },
        contains(haystack, needle) {
            if (this.isNA(haystack)) return false;
            if (Array.isArray(haystack)) return haystack.includes(needle);
            return String(haystack).indexOf(String(needle)) > -1;
        },
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
                if (!(key in this)) this[key] = null;
            });
            inputs.forEach((inp) => this._syncInput(inp));
            this.$root.addEventListener('input', (e) => this._syncInput(e.target));
            this.$root.addEventListener('change', (e) => this._syncInput(e.target));
        },

        _syncInput(inp) {
            const raw = inp.name || '';
            if (!raw || raw.startsWith('_item_views')) return;
            if (inp.disabled) return;
            const isArr = raw.endsWith('[]');
            const key = isArr ? raw.slice(0, -2) : raw;
            const coerce = (s) => {
                if (s === '' || s === null || s === undefined) return null;
                const n = Number(s);
                return isNaN(n) ? s : n;
            };
            let v;
            if (inp.type === 'checkbox') {
                if (isArr) {
                    const boxes = this.$root.querySelectorAll(
                        `input[type=checkbox][name="${CSS.escape(raw)}"]:checked`
                    );
                    v = Array.from(boxes).map((b) => coerce(b.value));
                } else {
                    v = inp.checked ? coerce(inp.value) : null;
                }
            } else if (inp.type === 'radio') {
                const checked = this.$root.querySelector(
                    `input[type=radio][name="${CSS.escape(raw)}"]:checked`
                );
                v = checked ? coerce(checked.value) : null;
            } else if (inp.tagName === 'SELECT' && inp.multiple) {
                v = Array.from(inp.selectedOptions).map((o) => coerce(o.value));
            } else {
                v = coerce(inp.value);
            }
            this[key] = v;
        },
    }));
}

export function registerXShowif(Alpine) {
    Alpine.directive('showif', (el, { expression }, { evaluateLater, effect }) => {
        const cleaned = (expression || '')
            .replace(/\/\*[\s\S]*?\*\//g, '')
            .replace(/\/\/.*$/gm, '')
            .trim();
        if (!cleaned) return;
        const rewritten = cleaned.replace(
            /\(\s*typeof\(\s*([A-Za-z0-9_'"]+)\s*\)\s*===\s*['"]undefined['"]\s*\)/g,
            'isNA($1)'
        );
        const safe = `(function(){try{return (${rewritten})}catch(e){return undefined}})()`;

        const applyVisibility = (visible) => {
            el.classList.toggle('hidden', !visible);
            el.toggleAttribute('data-fmr-hidden', !visible);
            el.style.display = visible ? '' : 'none';
            el.querySelectorAll('input, select, textarea').forEach((inp) => {
                if (inp.name && !inp.name.startsWith('_item_views')) {
                    inp.disabled = !visible;
                }
            });
        };

        let getValue;
        try {
            getValue = evaluateLater(safe);
        } catch {
            applyVisibility(true);
            return;
        }
        effect(() => {
            getValue((result) => {
                const visible = (result === undefined) ? true : !!result;
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
