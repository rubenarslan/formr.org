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

    const applyErrors = (pageEl, errors) => {
        Object.entries(errors).forEach(([name, msg]) => {
            const el = pageEl.querySelector(`[name="${CSS.escape(name)}"]`);
            if (el) el.setCustomValidity(String(msg));
        });
        pageEl.reportValidity();
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
