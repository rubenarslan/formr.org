// form_v2 participant bundle (Phase 1).
// Single-page AJAX form: all pages rendered server-side in one document,
// client-side navigation between `<section data-fmr-page>` wrappers,
// page-at-a-time AJAX submission. Alpine is registered for later phases
// but Phase 1 only uses vanilla event handlers.

import 'bootstrap5/dist/css/bootstrap.min.css';
import '@fortawesome/fontawesome-free/css/all.min.css';
import '../css/form.scss';

import Alpine from 'alpinejs';

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

    const stampShown = (pageEl) => {
        const nowSql = mysqlDatetime();
        const relMs = Math.round(performance.now());
        pageEl.querySelectorAll('.item_shown').forEach((inp) => {
            if (!inp.value) inp.value = nowSql;
        });
        pageEl.querySelectorAll('.item_shown_relative').forEach((inp) => {
            if (!inp.value) inp.value = String(relMs);
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
            stampShown(p);
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
            const name = inp.name || '';
            const m = name.match(/^_item_views\[(\w+)\]\[(\d+)\]$/);
            if (m) {
                itemViews[m[1]] = itemViews[m[1]] || {};
                itemViews[m[1]][m[2]] = inp.value;
                return;
            }
            if (inp.type === 'checkbox') {
                if (inp.checked) {
                    if (data[name] !== undefined) {
                        if (!Array.isArray(data[name])) data[name] = [data[name]];
                        data[name].push(inp.value);
                    } else {
                        data[name] = inp.value;
                    }
                }
            } else if (inp.type === 'radio') {
                if (inp.checked) data[name] = inp.value;
            } else if (inp.type === 'file') {
                // Phase 1: file uploads fall through to server error; offline queue lands in Phase 5.
            } else if (inp.disabled) {
                // skip disabled
            } else {
                data[name] = inp.value;
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
