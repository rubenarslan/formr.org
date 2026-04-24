// form_v2 participant bundle (Phase 1).
// Single-page AJAX form: all pages rendered server-side in one document,
// client-side navigation between `<section data-fmr-page>` wrappers,
// page-at-a-time AJAX submission. Alpine is registered for later phases
// but Phase 1 only uses vanilla event handlers.

import 'bootstrap5/dist/css/bootstrap.min.css';
import '@fortawesome/fontawesome-free/css/all.min.css';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';
import '../css/form.scss';

import Alpine from 'alpinejs';
import TomSelect from 'tom-select';

function initForm() {
    const root = document.querySelector('.fmr-form-v2');
    if (!root) return;

    const pages = Array.from(root.querySelectorAll('[data-fmr-page]'));
    if (pages.length === 0) return;

    let currentIndex = 0;

    const progressBar = root.querySelector('[data-fmr-progress-bar]');
    const progressLabel = root.querySelector('[data-fmr-progress-label]');

    // MySQL datetime column format (matches the v1 helper in common/js/main.js).
    const mysqlDatetime = () => new Date().toISOString().slice(0, 19).replace('T', ' ');

    // IntersectionObserver-based `shown` timing: only stamp an item once it
    // actually enters the viewport. Server-side SpreadsheetRenderer sets these
    // fields to mysql_now() only if the client never populated them, so we can
    // keep filling them lazily.
    const stampItemShown = (itemEl) => {
        if (itemEl.dataset.fmrShown === '1') return;
        itemEl.dataset.fmrShown = '1';
        const nowSql = mysqlDatetime();
        const relMs = Math.round(performance.now());
        const shown = itemEl.querySelector('.item_shown');
        const shownRel = itemEl.querySelector('.item_shown_relative');
        if (shown && !shown.value) shown.value = nowSql;
        if (shownRel && !shownRel.value) shownRel.value = String(relMs);
    };

    const itemIntersectionObserver = ('IntersectionObserver' in window)
        ? new IntersectionObserver((entries) => {
              entries.forEach((entry) => {
                  if (entry.isIntersecting) {
                      stampItemShown(entry.target);
                      itemIntersectionObserver.unobserve(entry.target);
                  }
              });
          }, { threshold: 0.25 })
        : null;

    const observeItems = (pageEl) => {
        if (!itemIntersectionObserver) {
            // Fallback: stamp everything immediately when the page becomes visible.
            pageEl.querySelectorAll('.form-group, .item').forEach((el) => stampItemShown(el));
            return;
        }
        pageEl.querySelectorAll('.form-group, .item').forEach((el) => {
            if (el.dataset.fmrShown !== '1') {
                itemIntersectionObserver.observe(el);
            }
        });
    };

    const showPage = (i) => {
        pages.forEach((p, idx) => { p.hidden = (idx !== i); });
        currentIndex = i;
        if (progressBar) {
            const pct = Math.round(((i + 1) / pages.length) * 100);
            progressBar.style.width = pct + '%';
            progressBar.setAttribute('aria-valuenow', String(pct));
        }
        if (progressLabel) {
            progressLabel.textContent = `Page ${i + 1} of ${pages.length}`;
        }
        const p = pages[i];
        if (p) {
            window.scrollTo({ top: 0, behavior: 'smooth' });
            observeItems(p);
        }
    };

    // Answered-timing: stamp on first value change per item.
    root.querySelectorAll('input[name], select[name], textarea[name]').forEach((inp) => {
        if (inp.name && inp.name.startsWith('_item_views')) return;
        inp.addEventListener('change', () => {
            const wrapper = inp.closest('.item');
            if (!wrapper) return;
            const ans = wrapper.querySelector('.item_answered');
            if (ans && !ans.value) ans.value = mysqlDatetime();
            const ansR = wrapper.querySelector('.item_answered_relative');
            if (ansR && !ansR.value) ansR.value = String(Math.round(performance.now()));
        });
    });

    const collectPayload = (pageEl) => {
        const data = {};
        const itemViews = { shown: {}, shown_relative: {}, answered: {}, answered_relative: {} };
        const fields = pageEl.querySelectorAll('input[name], select[name], textarea[name]');
        fields.forEach((inp) => {
            const rawName = inp.name || '';
            const viewMatch = rawName.match(/^_item_views\[(\w+)\]\[(\d+)\]$/);
            if (viewMatch) {
                itemViews[viewMatch[1]] = itemViews[viewMatch[1]] || {};
                itemViews[viewMatch[1]][viewMatch[2]] = inp.value;
                return;
            }

            // Name with `[]` suffix is an array field (mc_multiple, select_multiple,
            // geopoint display, etc.). Strip the suffix for the key. Everything
            // else is scalar, last-value-wins — same as PHP's $_POST parsing.
            let name = rawName;
            let isArray = false;
            if (rawName.endsWith('[]')) {
                name = rawName.slice(0, -2);
                isArray = true;
            }

            let value;
            if (inp.type === 'checkbox') {
                if (!inp.checked) return;
                value = inp.value;
            } else if (inp.type === 'radio') {
                if (!inp.checked) return;
                value = inp.value;
            } else if (inp.type === 'file') {
                // Phase 2 territory — needs multipart/FormData. Skipped on the JSON path.
                return;
            } else if (inp.disabled) {
                return;
            } else if (inp.tagName === 'SELECT' && inp.multiple) {
                value = Array.from(inp.selectedOptions).map((o) => o.value);
                isArray = true; // multi-select always emits an array
            } else {
                value = inp.value;
            }

            if (isArray) {
                if (Array.isArray(value)) {
                    data[name] = (data[name] || []).concat(value);
                } else {
                    data[name] = (data[name] || []).concat([value]);
                }
            } else {
                data[name] = value; // last-wins
            }
        });
        return { data, item_views: itemViews };
    };

    const clearCustomValidity = (pageEl) => {
        pageEl.querySelectorAll('input[name], select[name], textarea[name]').forEach((inp) => {
            inp.setCustomValidity('');
        });
    };

    const findErrorTarget = (pageEl, name) => {
        // First try the exact input name.
        let el = pageEl.querySelector(`[name="${CSS.escape(name)}"]`);
        // Geopoint / multi-field items submit via `name` but the visible input
        // is `name[]`. Fall back to the array-suffixed version.
        if (!el) el = pageEl.querySelector(`[name="${CSS.escape(name + '[]')}"]`);
        // Some items may only expose a wrapper via `.item-<name>` — not common
        // enough to chase here; fall back to null and we'll render a top-banner.
        return el;
    };

    const applyErrors = (pageEl, errors) => {
        // Clear previous BS5-style error state.
        pageEl.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        pageEl.querySelectorAll('.fmr-invalid-feedback').forEach((el) => el.remove());

        let unplaced = [];
        Object.entries(errors).forEach(([name, msg]) => {
            const el = findErrorTarget(pageEl, name);
            if (!el) {
                unplaced.push({ name, msg: String(msg) });
                return;
            }
            el.setCustomValidity(String(msg));
            el.classList.add('is-invalid');
            const feedback = document.createElement('div');
            feedback.className = 'invalid-feedback fmr-invalid-feedback d-block';
            feedback.textContent = String(msg);
            // Insert after the input's immediate parent (so it lines up with BS5 form-control siblings).
            const anchor = el.closest('.controls, .form-group') || el.parentElement;
            if (anchor && anchor.parentElement) {
                anchor.parentElement.insertBefore(feedback, anchor.nextSibling);
            } else {
                el.insertAdjacentElement('afterend', feedback);
            }
            // Scroll the first offender into view.
            if (!pageEl.dataset.fmrScrolledToError) {
                pageEl.dataset.fmrScrolledToError = '1';
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
        delete pageEl.dataset.fmrScrolledToError;

        if (unplaced.length) {
            let banner = pageEl.querySelector('.fmr-error-banner');
            if (!banner) {
                banner = document.createElement('div');
                banner.className = 'alert alert-danger fmr-error-banner';
                banner.setAttribute('role', 'alert');
                pageEl.insertBefore(banner, pageEl.firstChild);
            }
            banner.innerHTML = unplaced.map((e) => `<div><strong>${e.name}:</strong> ${e.msg}</div>`).join('');
        }

        pageEl.reportValidity();
    };

    // Geopoint item: wire navigator.geolocation to the .geolocator button. v1
    // did this via webshim + jQuery; the v2 bundle has neither dep.
    const flatStringifyGeo = (pos) => JSON.stringify({
        timestamp: pos.timestamp,
        coords: {
            accuracy: pos.coords.accuracy,
            altitude: pos.coords.altitude,
            altitudeAccuracy: pos.coords.altitudeAccuracy,
            heading: pos.coords.heading,
            latitude: pos.coords.latitude,
            longitude: pos.coords.longitude,
            speed: pos.coords.speed,
        },
    });

    const initGeopoint = () => {
        if (!('geolocation' in navigator)) return;
        root.querySelectorAll('.geolocator').forEach((btn) => {
            // v1 wraps the button in <span class="input-group-btn hidden">;
            // show it now that JS is up.
            const wrapper = btn.closest('.input-group-btn');
            if (wrapper && wrapper.classList.contains('hidden')) {
                wrapper.classList.remove('hidden');
            }
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                const controls = btn.closest('.controls');
                if (!controls) return;
                const hidden = controls.querySelector('input[type=hidden]');
                const visible = controls.querySelector('input[type=text]');
                if (visible) {
                    visible.placeholder = 'You can also enter your location manually';
                    visible.removeAttribute('readonly');
                }
                navigator.geolocation.getCurrentPosition(
                    (pos) => {
                        if (hidden) hidden.value = flatStringifyGeo(pos);
                        if (visible) {
                            visible.value = `lat:${pos.coords.latitude}/long:${pos.coords.longitude}`;
                            visible.setAttribute('readonly', 'readonly');
                        }
                        // Clear any prior error state for this item.
                        controls.querySelectorAll('.is-invalid').forEach((el) => {
                            el.classList.remove('is-invalid');
                            el.setCustomValidity('');
                        });
                        controls.parentElement && controls.parentElement.querySelectorAll('.fmr-invalid-feedback').forEach((el) => el.remove());
                    },
                    () => {
                        // Permission denied or unavailable — the visible field is now editable for manual entry.
                    },
                );
            });
        });
    };

    const submitUrl = root.dataset.submitUrl;
    const runUrl = root.dataset.runUrl;

    const submitPage = async () => {
        const page = pages[currentIndex];
        clearCustomValidity(page);
        // native validation
        const invalid = page.querySelector(':invalid');
        if (invalid) {
            invalid.reportValidity();
            return;
        }
        const payload = Object.assign(
            { page: Number(page.dataset.fmrPage) || (currentIndex + 1) },
            collectPayload(page),
        );
        let res;
        try {
            res = await fetch(submitUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: JSON.stringify(payload),
            });
        } catch (e) {
            console.error('page-submit network error', e);
            window.alert('Network error. Please try again.');
            return;
        }
        if (!res.ok) {
            console.error('page-submit HTTP', res.status);
            window.alert('Sorry, something went wrong. Please try again.');
            return;
        }
        const body = await res.json().catch(() => null);
        if (!body) return;
        if (body.status === 'errors' && body.errors) {
            applyErrors(page, body.errors);
            return;
        }
        if (body.redirect) {
            window.location.href = body.redirect;
            return;
        }
        if (typeof body.next_page === 'number') {
            const nextIdx = pages.findIndex((p) => Number(p.dataset.fmrPage) === body.next_page);
            if (nextIdx !== -1) {
                showPage(nextIdx);
                try { history.pushState(null, '', `?page=${nextIdx + 1}`); } catch (e) { /* noop */ }
                return;
            }
        }
        if (body.move_on || body.end_session) {
            window.location.href = runUrl || window.location.pathname;
        }
    };

    root.querySelectorAll('[data-fmr-next]').forEach((btn) => {
        btn.addEventListener('click', (e) => { e.preventDefault(); submitPage(); });
    });
    root.querySelectorAll('[data-fmr-prev]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            if (currentIndex > 0) showPage(currentIndex - 1);
        });
    });

    // Block implicit form submit (Enter key) from doing a real POST — funnel through submitPage.
    root.addEventListener('submit', (e) => { e.preventDefault(); submitPage(); });

    initGeopoint();

    // --- Client-side showif (Phase 3) ---
    // Each item wrapper with a `data-showif` carries a JS expression (transpiled
    // from the admin's R `showif` at import). Evaluate it against the live
    // answers object on every input/change and toggle the wrapper's hidden state.
    const showifItems = Array.from(root.querySelectorAll('.form-group[data-showif], .item[data-showif]'));

    const collectAnswers = () => {
        const a = {};
        root.querySelectorAll('input[name], select[name], textarea[name]').forEach((inp) => {
            const raw = inp.name || '';
            if (raw.startsWith('_item_views')) return;
            const isArr = raw.endsWith('[]');
            const key = isArr ? raw.slice(0, -2) : raw;
            let v;
            if (inp.type === 'checkbox') {
                if (!inp.checked) return;
                v = inp.value;
            } else if (inp.type === 'radio') {
                if (!inp.checked) return;
                v = inp.value;
            } else if (inp.disabled) {
                return;
            } else {
                v = inp.value;
            }
            const n = v === '' || v === null || v === undefined ? null : (isNaN(Number(v)) ? v : Number(v));
            if (isArr) {
                a[key] = (a[key] || []).concat([n]);
            } else {
                a[key] = n;
            }
        });
        return a;
    };

    // Compile each data-showif once. On failure, the item is treated as visible.
    const compileShowif = (expr) => {
        const cleaned = (expr || '').replace(/\/\*[\s\S]*?\*\/|\/\/.*/g, '').trim();
        if (!cleaned) return () => true;
        try {
            // eslint-disable-next-line no-new-func
            const fn = new Function('context', 'with (context) { return (' + cleaned + '); }');
            return (ctx) => {
                try { return !!fn(ctx); } catch (e) { return true; /* on failure show */ }
            };
        } catch (e) {
            return () => true;
        }
    };
    const compiled = showifItems.map((el) => ({ el, fn: compileShowif(el.getAttribute('data-showif')) }));

    const applyShowifs = () => {
        if (!compiled.length) return;
        const answers = collectAnswers();
        compiled.forEach(({ el, fn }) => {
            const visible = fn(answers);
            // v1's hide() tacks on .hidden which ships display:none !important in
            // Bootstrap, so toggling class is load-bearing; style.display alone
            // can't override it.
            el.classList.toggle('hidden', !visible);
            el.toggleAttribute('data-fmr-hidden', !visible);
            el.style.display = visible ? '' : 'none';
            // Disable inputs of hidden items so native `required` doesn't block
            // form submission, and so their values don't get sent.
            el.querySelectorAll('input, select, textarea').forEach((inp) => {
                if (inp.name && !inp.name.startsWith('_item_views')) {
                    inp.disabled = !visible;
                }
            });
        });
    };

    // Re-evaluate on any user input.
    root.addEventListener('input', applyShowifs);
    root.addEventListener('change', applyShowifs);
    applyShowifs(); // initial pass

    // Tom-select on <select> elements. v1 auto-wired select2; v2 mirrors that
    // using tom-select so the participant bundle stays jQuery-free. Large
    // dropdowns (timezone list, big choice lists) get the search input; small
    // ones render as a styled click-to-open menu.
    root.querySelectorAll('select').forEach((sel) => {
        if (!sel.name || sel.name === '') return; // skip the progress <select> chrome, if any
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

    // Initial page from ?page=N
    const paramPage = Number(new URLSearchParams(window.location.search).get('page'));
    const startIdx = Math.max(0, Math.min(pages.length - 1, (paramPage || 1) - 1));
    showPage(startIdx);

    window.addEventListener('popstate', () => {
        const p = Number(new URLSearchParams(window.location.search).get('page'));
        const idx = Math.max(0, Math.min(pages.length - 1, (p || 1) - 1));
        showPage(idx);
    });
}

window.Alpine = Alpine;

document.addEventListener('DOMContentLoaded', () => {
    initForm();
    Alpine.start();
});
