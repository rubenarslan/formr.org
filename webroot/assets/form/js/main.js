// form_v2 participant bundle.
// Single-page AJAX form: all pages rendered server-side in one document,
// client-side navigation between `<section data-fmr-page>` wrappers, page-at-
// a-time AJAX submission. Alpine drives reactive `showif` (`x-showif`
// directive + `fmrForm` component); everything else (r-call debounce, offline
// queue, button-group wiring, file uploads, geopoint) is plain DOM/fetch.

import 'bootstrap5/dist/css/bootstrap.min.css';
import '@fortawesome/fontawesome-free/css/all.min.css';
// v4-shims maps the FA4.7 `fa-foo-o` / `fa-bar` class names the v1 templates
// (notably monkey_bar, Item::render) still emit onto FA6 icons. Without this
// import the icons render as blank squares in the v2 bundle even though the
// font is loaded.
import '@fortawesome/fontawesome-free/css/v4-shims.min.css';
import 'tom-select/dist/css/tom-select.bootstrap5.min.css';
// custom_item_classes.css carries admin-choosable layout modifiers (mc_width50
// … mc_width200, rotate_label45/30/90, mc_vertical, mc_block, mc_equal_widths,
// rating_button_label_width*, hide_label, …). These are `form.form-horizontal`
// -scoped, which is why FormRenderer adds `form-horizontal` to the v2 form.
import '../../common/css/custom_item_classes.css';
import '../css/form.scss';

import Alpine from 'alpinejs';
import TomSelect from 'tom-select';
// PWA install UX: same library pair v1 uses.
//   - `add-to-homescreen` renders the polished cross-platform install modal
//     with iOS / Safari / Chrome-specific guidance.
//   - `@khmyznikov/pwa-install` is a web component that wraps the native
//     `beforeinstallprompt` flow so we get a Chrome / Edge install dialog.
// The two work together: the browser fires `beforeinstallprompt` which the
// web component captures; on a participant click we either invoke the web
// component (when a native prompt is queued) or fall back to the
// AddToHomeScreen modal (when there's no programmatic install API).
import AddToHomeScreen from 'add-to-homescreen/dist/add-to-homescreen.min.js';
import '@khmyznikov/pwa-install';

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
    // /form-render-page resolves dynamic labels + values for the upcoming
    // page in one OpenCPU batch. Derived from runUrl rather than threaded
    // through a new data-* attribute so we don't churn the FormRenderer
    // template just for this URL.
    const renderPageUrl = (runUrl || '').replace(/\/?$/, '/') + 'form-render-page';
    // Admin-controlled per-study flags (SurveyStudy.offline_mode /
    // .allow_previous). Default to on/off respectively when the attribute is
    // missing (safe for pre-patch-051 forms: unchanged v2 behaviour).
    const offlineModeEnabled = (root.dataset.offlineMode || 'on') !== 'off';
    // When offline mode is off, don't expose the sync URL to the queue path —
    // submissions fail hard as if the endpoint didn't exist.
    const syncUrl = offlineModeEnabled ? root.dataset.syncUrl : '';

    // --- Offline queue (Phase 5) ---
    // When a JSON page-submit fails with a network error, persist the payload
    // to IndexedDB keyed by a client-generated UUID and let the participant
    // continue locally. On `online` or at next page load, drain the queue by
    // POSTing each entry to /form-sync (server dedups via
    // survey_form_submissions.uuid so retries are safe). File uploads still
    // take the online-only multipart path — we don't serialize Blobs yet.
    const IDB_NAME = 'formrQueue';
    const IDB_STORE = 'queue';
    const openQueueDB = () => new Promise((resolve, reject) => {
        if (!('indexedDB' in window)) { reject(new Error('no indexeddb')); return; }
        const req = indexedDB.open(IDB_NAME, 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains(IDB_STORE)) {
                const store = db.createObjectStore(IDB_STORE, { keyPath: 'uuid' });
                store.createIndex('client_ts', 'client_ts');
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
    const idbTx = async (mode, fn) => {
        const db = await openQueueDB();
        return new Promise((resolve, reject) => {
            const tx = db.transaction(IDB_STORE, mode);
            const store = tx.objectStore(IDB_STORE);
            const result = fn(store);
            tx.oncomplete = () => resolve(result);
            tx.onerror = () => reject(tx.error);
            tx.onabort = () => reject(tx.error);
        });
    };
    const queueAdd = (entry) => idbTx('readwrite', (s) => { s.put(entry); return entry; });
    const queueGetAll = () => idbTx('readonly', (s) => new Promise((res) => {
        const req = s.getAll();
        req.onsuccess = () => res(req.result || []);
        req.onerror = () => res([]);
    })).then((p) => p);
    const queueDelete = (uuid) => idbTx('readwrite', (s) => s.delete(uuid));

    const genUuid = () => (typeof crypto !== 'undefined' && crypto.randomUUID)
        ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
              const r = (Math.random() * 16) | 0;
              const v = c === 'x' ? r : ((r & 0x3) | 0x8);
              return v.toString(16);
          });

    // Queued-submission banner. Single DOM node reused across enqueue/drain.
    let queueBanner = null;
    const showQueueBanner = (msg, variant) => {
        if (!queueBanner) {
            queueBanner = document.createElement('div');
            queueBanner.className = 'alert fmr-queue-banner';
            queueBanner.setAttribute('role', 'status');
            root.insertBefore(queueBanner, root.firstChild);
        }
        queueBanner.classList.remove('alert-warning', 'alert-success', 'alert-danger');
        queueBanner.classList.add(variant === 'success' ? 'alert-success'
            : variant === 'danger' ? 'alert-danger' : 'alert-warning');
        queueBanner.textContent = msg;
        queueBanner.hidden = false;
    };
    const hideQueueBanner = () => { if (queueBanner) queueBanner.hidden = true; };

    // Network-ish failure: fetch() rejected (no response) OR a 5xx server error.
    // 4xx is a real "server said no" — don't queue, bubble up as before.
    const isTransientFailure = (err, res) =>
        (err != null) || (res && res.status >= 500 && res.status < 600);

    // Build a FormData representation of a queued entry that has `files`.
    // Keys mirror form-page-submit's multipart branch (see formSyncAction).
    const buildSyncFormData = (entry) => {
        const fd = new FormData();
        fd.append('uuid', entry.uuid);
        fd.append('page', String(entry.page));
        if (entry.client_ts) fd.append('client_ts', entry.client_ts);
        const data = entry.data || {};
        Object.keys(data).forEach((k) => {
            const v = data[k];
            if (Array.isArray(v)) {
                v.forEach((vv) => fd.append(`data[${k}][]`, vv == null ? '' : String(vv)));
            } else if (v != null) {
                fd.append(`data[${k}]`, String(v));
            }
        });
        const views = entry.item_views || {};
        Object.keys(views).forEach((bucket) => {
            const m = views[bucket] || {};
            Object.keys(m).forEach((id) => fd.append(`item_views[${bucket}][${id}]`, String(m[id])));
        });
        const files = entry.files || {};
        Object.keys(files).forEach((itemName) => {
            const f = files[itemName];
            if (!f) return;
            // IDB returns Blobs (File is a subclass of Blob). Preserve the
            // original filename via the `name` property if present.
            const fname = (f.name || itemName);
            fd.append(`files[${itemName}]`, f, fname);
        });
        return fd;
    };

    const drainQueue = async () => {
        if (!syncUrl) return;
        let entries;
        try { entries = await queueGetAll(); } catch (e) { return; }
        if (!entries || !entries.length) { hideQueueBanner(); return; }
        entries.sort((a, b) => (a.client_ts || '').localeCompare(b.client_ts || ''));
        showQueueBanner(`Syncing ${entries.length} queued submission${entries.length === 1 ? '' : 's'}…`, 'warning');
        for (const entry of entries) {
            const hasFiles = entry.files && Object.keys(entry.files).length > 0;
            let res;
            try {
                if (hasFiles) {
                    // Multipart drain — mirrors the submitPage multipart path.
                    res = await fetch(syncUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                        body: buildSyncFormData(entry),
                    });
                } else {
                    res = await fetch(syncUrl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify(entry),
                    });
                }
            } catch (e) {
                // Still offline / transient. Leave entries in place; next `online` retries.
                showQueueBanner('Offline — submissions will sync automatically when you reconnect.', 'warning');
                return;
            }
            const body = await res.json().catch(() => null);
            if (res.ok || (body && body.already_applied)) {
                await queueDelete(entry.uuid);
                // If the drain applied the final page of the form, the server
                // returns a redirect so Run::exec can advance. Take it once
                // the queue is empty so the participant lands on the next unit.
                if (body && body.redirect) {
                    const remaining = await queueGetAll();
                    if (!remaining.length) {
                        window.location.href = body.redirect;
                        return;
                    }
                }
                continue;
            }
            if (body && body.drop_entry) {
                // Session ended / not applicable — stop retrying this entry.
                await queueDelete(entry.uuid);
                continue;
            }
            // 4xx / validation failure. Stop draining; the user needs to see the error.
            showQueueBanner('A queued submission was rejected. Please review the page.', 'danger');
            return;
        }
        const remaining = await queueGetAll();
        if (!remaining.length) {
            showQueueBanner('All queued submissions have been sent.', 'success');
            setTimeout(hideQueueBanner, 3000);
        }
    };

    // --- Page-transition resolver (POST /form-render-page) ---
    // Resolves the upcoming page's dynamic labels + values via the batched
    // OpenCPU endpoint. Called between submit-success and showPage so the
    // participant sees the resolved content as soon as the new page appears.
    // No-op for pages without any dynamic items (no data-fmr-fill-id /
    // -label-id placeholders) — short-circuits with no network.
    const resolveAndSubstitutePage = async (pageNum) => {
        const targetPage = pages.find((p) => Number(p.dataset.fmrPage) === Number(pageNum));
        if (!targetPage) return;
        const fillItems = targetPage.querySelectorAll('[data-fmr-fill-id]');
        const labelItems = targetPage.querySelectorAll('[data-fmr-label-id]');
        if (fillItems.length === 0 && labelItems.length === 0) return;
        // Mark items pending while we wait — visual feedback.
        fillItems.forEach((el) => el.classList.add('fmr-fill-pending'));
        labelItems.forEach((el) => el.classList.add('fmr-fill-pending'));
        let body;
        try {
            const res = await fetch(renderPageUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ page: Number(pageNum), answers: collectAllAnswers() }),
            });
            body = await res.json().catch(() => null);
            if (!res.ok || !body) {
                fillItems.forEach((el) => el.classList.remove('fmr-fill-pending'));
                labelItems.forEach((el) => el.classList.remove('fmr-fill-pending'));
                console.warn('form-render-page failed', res.status, body);
                return;
            }
        } catch (e) {
            // Network failure — leave placeholders in place and let the
            // participant continue. They'll see whatever's in the static
            // label / blank value field; not great but not fatal.
            fillItems.forEach((el) => el.classList.remove('fmr-fill-pending'));
            labelItems.forEach((el) => el.classList.remove('fmr-fill-pending'));
            console.warn('form-render-page network error', e);
            return;
        }
        const valuesMap = (body && body.values) || {};
        const labelsMap = (body && body.labels) || {};
        // Substitute values: write to the first named input under the
        // wrapper. Don't clobber a value the participant already edited
        // (back-nav case).
        fillItems.forEach((wrapper) => {
            wrapper.classList.remove('fmr-fill-pending');
            const id = wrapper.dataset.fmrFillId;
            if (!(id in valuesMap)) return;
            const val = valuesMap[id];
            const target = wrapper.querySelector('input[name], textarea[name], select[name]');
            if (!target) return;
            if (target.value && target.value !== '') return; // user already edited
            target.value = val == null ? '' : String(val);
            target.dispatchEvent(new Event('input', { bubbles: true }));
            target.dispatchEvent(new Event('change', { bubbles: true }));
        });
        // Substitute labels: replace the .control-label inner content. v1
        // emits HTML there (knit2html result), so innerHTML is correct.
        // The R came from survey_r_calls (admin-trusted), evaluated server-
        // side, returned as HTML. Same trust model as v1 inline knit.
        labelItems.forEach((wrapper) => {
            wrapper.classList.remove('fmr-fill-pending');
            const id = wrapper.dataset.fmrLabelId;
            if (!(id in labelsMap)) return;
            const html = labelsMap[id];
            const labelEl = wrapper.querySelector('.control-label, label.control-label');
            if (labelEl) labelEl.innerHTML = html;
        });
    };

    // Read the current state of every named form input as a flat map. Used
    // for /form-render-page (and /form-r-call / /form-fill on the JS side).
    // Same coercion as Alpine's _syncInput so the server sees identical
    // shape regardless of which path it came from.
    const collectAllAnswers = () => {
        const out = {};
        root.querySelectorAll('input[name], textarea[name], select[name]').forEach((inp) => {
            if (!inp.name || inp.name.startsWith('_item_views')) return;
            if (inp.disabled) return;
            const isArr = inp.name.endsWith('[]');
            const key = isArr ? inp.name.slice(0, -2) : inp.name;
            const coerce = (s) => {
                if (s === '' || s === null || s === undefined) return null;
                const n = Number(s);
                return isNaN(n) ? s : n;
            };
            if (inp.type === 'checkbox' || inp.type === 'radio') {
                if (!inp.checked) return;
                if (isArr) {
                    if (!Array.isArray(out[key])) out[key] = [];
                    out[key].push(coerce(inp.value));
                } else {
                    out[key] = coerce(inp.value);
                }
            } else if (inp.tagName === 'SELECT' && inp.multiple) {
                out[key] = Array.from(inp.selectedOptions).map((o) => coerce(o.value));
            } else {
                if (isArr) {
                    if (!Array.isArray(out[key])) out[key] = [];
                    if (inp.value !== '') out[key].push(coerce(inp.value));
                } else {
                    out[key] = coerce(inp.value);
                }
            }
        });
        return out;
    };

    const submitPage = async () => {
        const page = pages[currentIndex];
        clearCustomValidity(page);
        // native validation
        const invalid = page.querySelector(':invalid');
        if (invalid) {
            invalid.reportValidity();
            return;
        }
        const pageNum = Number(page.dataset.fmrPage) || (currentIndex + 1);
        const payload = Object.assign({ page: pageNum }, collectPayload(page));

        // If this page has a file input with a selected file, switch to
        // FormData/multipart. File bytes can't ride in JSON, and the server
        // branches on Content-Type to read $_FILES for File_Item items.
        const fileInputs = Array.from(
            page.querySelectorAll('input[type="file"]:not([disabled])')
        ).filter((inp) => inp.name && inp.files && inp.files.length > 0);
        const useMultipart = fileInputs.length > 0;

        let res, netErr;
        try {
            if (useMultipart) {
                const fd = new FormData();
                fd.append('page', String(pageNum));
                const appendVal = (prefix, key, val) => {
                    if (Array.isArray(val)) {
                        val.forEach((v) => fd.append(`${prefix}[${key}][]`, v == null ? '' : String(v)));
                    } else if (val != null) {
                        fd.append(`${prefix}[${key}]`, String(val));
                    }
                };
                const data = payload.data || {};
                Object.keys(data).forEach((k) => appendVal('data', k, data[k]));
                const views = payload.item_views || {};
                Object.keys(views).forEach((bucket) => {
                    const m = views[bucket] || {};
                    Object.keys(m).forEach((id) => fd.append(`item_views[${bucket}][${id}]`, String(m[id])));
                });
                fileInputs.forEach((inp) => {
                    // Drop this item from `data` — File_Item reads from $_FILES['files'][itemName].
                    fd.delete(`data[${inp.name}]`);
                    fd.append(`files[${inp.name}]`, inp.files[0], inp.files[0].name);
                });
                res = await fetch(submitUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: fd,
                });
            } else {
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
            }
        } catch (e) {
            netErr = e;
        }
        // Offline / server-5xx → persist the submission to IDB and keep the
        // participant moving. When offline_mode is off (admin opt-out),
        // syncUrl is empty and we bubble the transient failure as a hard
        // error instead. File-bearing submissions also skip queueing when any
        // single file exceeds QUEUE_FILE_SIZE_CAP (default 10 MB) — storing
        // large Blobs in IDB can blow out the browser's per-origin quota and
        // leave the queue in an undrainable state.
        const QUEUE_FILE_SIZE_CAP = 10 * 1024 * 1024;
        if (isTransientFailure(netErr, res)) {
            const oversizedFile = useMultipart
                ? fileInputs.find((inp) => inp.files[0] && inp.files[0].size > QUEUE_FILE_SIZE_CAP)
                : null;
            if (!syncUrl || oversizedFile) {
                console.error('page-submit offline (offline queue disabled or file too large)', netErr || (res && res.status));
                const msg = oversizedFile
                    ? `Submission too large to queue offline (${(oversizedFile.files[0].size / 1024 / 1024).toFixed(1)} MB, limit 10 MB). Please retry when you're back online.`
                    : 'Your submission could not be sent. Please check your connection and try again.';
                window.alert(msg);
                return;
            }
            const entry = {
                uuid: genUuid(),
                page: pageNum,
                data: Object.assign({}, payload.data),
                item_views: payload.item_views,
                // MySQL DATETIME rejects ISO-8601 with ".sssZ" — same gotcha as
                // item_shown timestamps. Use the space-separated format.
                client_ts: mysqlDatetime(),
            };
            // Stash the File objects separately and strip their placeholders
            // out of `data` so the server's $_POST['data'] doesn't end up
            // with a stringified File next to the $_FILES entry.
            if (useMultipart) {
                entry.files = {};
                fileInputs.forEach((inp) => {
                    const f = inp.files[0];
                    if (f) {
                        entry.files[inp.name] = f;
                        delete entry.data[inp.name];
                    }
                });
            }
            try { await queueAdd(entry); } catch (e) {
                console.error('enqueue failed', e);
                window.alert('Your submission could not be queued locally. Please check your connection and try again.');
                return;
            }
            // Ask the SW to wake us up on next connectivity change. No-op on
            // browsers without Background Sync — page-JS online event still
            // catches it there.
            registerBackgroundSync();
            showQueueBanner('You\'re offline. This submission is queued and will be sent when you reconnect.', 'warning');
            // Advance locally so the participant can continue — next drain
            // applies in order server-side. If this was the last page, leave
            // them on it; we don't know the run's next unit until a successful
            // sync drains and the redirect fires.
            const nextIdx = currentIndex + 1;
            if (nextIdx < pages.length) {
                showPage(nextIdx);
                try { history.pushState(null, '', `?page=${nextIdx + 1}`); } catch (e) { /* noop */ }
            }
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
                // Resolve the next page's dynamic labels + values via the
                // batched OpenCPU endpoint BEFORE showing it. This is the
                // page-scoped model: page 1 was resolved server-side at
                // initial render, but pages 2..N have placeholders that
                // need filling against the just-persisted answer state.
                await resolveAndSubstitutePage(body.next_page);
                showPage(nextIdx);
                try { history.pushState(null, '', `?page=${nextIdx + 1}`); } catch (e) { /* noop */ }
                return;
            }
        }
        if (body.move_on || body.end_session) {
            window.location.href = runUrl || window.location.pathname;
        }
    };

    // Drain triggers: browser-reported connectivity change + initial load (in
    // case the tab was reloaded with entries still pending).
    window.addEventListener('online', () => { drainQueue(); });
    drainQueue();

    // Register the service worker unconditionally on v2 runs and hand it the
    // sync URL so Background Sync wake-ups can drain the queue when the tab
    // is closed. iOS Safari doesn't implement sync — the page `online` path
    // already handles that case; this is pure upside on Chromium/Firefox/
    // Android Chrome. Silent on failure (e.g. insecure context).
    //
    // SW is served by RunController::serviceWorkerAction at /{runName}/service-worker
    // (path-based deploy) or /service-worker (subdomain deploy). The controller
    // sets `Service-Worker-Allowed: /{runName}/` so the scope can cover the
    // whole run path; registering at /assets/common/js/service-worker.js
    // directly would scope to that dir and miss the form POSTs.
    const registerFormSW = async () => {
        if (!('serviceWorker' in navigator) || !syncUrl) return;
        try {
            const runUrlObj = new URL(runUrl || window.location.href);
            const siteOrigin = window.location.origin;
            const isPathBased = runUrlObj.origin === siteOrigin && runUrlObj.pathname !== '/';
            const swPath = isPathBased ? runUrlObj.pathname.replace(/\/$/, '') + '/service-worker' : '/service-worker';
            const scope = isPathBased ? runUrlObj.pathname : '/';
            const existing = await navigator.serviceWorker.getRegistration(scope);
            const reg = existing || await navigator.serviceWorker.register(swPath, { scope });
            const sw = reg.active || reg.waiting || reg.installing || navigator.serviceWorker.controller;
            if (sw) sw.postMessage({ type: 'FMR_REGISTER_SYNC_URL', url: syncUrl });
        } catch (e) {
            console.warn('form-v2 SW registration failed', e);
        }
    };
    registerFormSW();
    const registerBackgroundSync = async () => {
        if (!('serviceWorker' in navigator)) return;
        try {
            const reg = await navigator.serviceWorker.ready;
            if (reg.sync) await reg.sync.register('form-v2-drain');
        } catch (e) { /* Background Sync not supported; page-side drain is the fallback. */ }
    };

    // --- PWA: AddToHomeScreen item ---
    // Two-part install UX, mirroring v1's PWAInstaller.js:
    //   1. `<pwa-install>` web component (from @khmyznikov/pwa-install) wraps
    //      `beforeinstallprompt`. When Chrome/Edge offers a native install,
    //      the component manages the dialog and fires success / fail events.
    //   2. `AddToHomeScreen` instance shows a polished modal with platform-
    //      specific guidance (Safari iOS instructions, Chrome Android prompt,
    //      desktop QR fallback). Used when the browser doesn't expose a
    //      programmatic install API.
    // We capture beforeinstallprompt early — the participant may have left
    // the install page already by the time it fires.
    let deferredInstallPrompt = null;
    let pwaInstallEl = null;
    let addToHomeInstance = null;

    const isStandaloneDisplayMode = () =>
        window.matchMedia('(display-mode: standalone)').matches
        || window.matchMedia('(display-mode: fullscreen)').matches
        || window.navigator.standalone === true;
    const isIOSDevice = () => /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredInstallPrompt = e;
        if (pwaInstallEl) pwaInstallEl.externalPromptEvent = e;
    });
    window.addEventListener('appinstalled', () => {
        deferredInstallPrompt = null;
        root.querySelectorAll('.add-to-homescreen-wrapper').forEach((w) => markAddedToHomeScreen(w, 'added'));
    });

    // Hidden-input values must match AddToHomeScreen_Item::validateInput's
    // allowlist: 'added', 'ios_not_prompted', 'not_requested', 'not_prompted',
    // 'already_added', 'no_support', 'not_added', 'prompted'.
    const markAddedToHomeScreen = (wrapper, value) => {
        const hidden = wrapper.querySelector('input');
        const status = wrapper.querySelector('.status-message');
        const btn = wrapper.querySelector('.add-to-homescreen, button.add-to-homescreen');
        if (hidden) { hidden.value = value; hidden.setCustomValidity(''); }
        if (status) status.textContent = 'You are using the installed app.';
        if (btn) {
            btn.disabled = true;
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-success');
            btn.innerHTML = '<i class="fa fa-check"></i> Installed';
        }
        wrapper.closest('.form-group')?.classList.add('formr_answered');
    };

    const setAtsStatus = (wrapper, value, text) => {
        const hidden = wrapper.querySelector('input');
        const status = wrapper.querySelector('.status-message');
        if (hidden) hidden.value = value;
        if (text && status) status.textContent = text;
    };

    // Lazy-init the pwa-install element + AddToHomeScreen instance once the
    // page has any add-to-homescreen items. Reads name + icon from the
    // manifest link in <head>; falls back to document.title + favicon.
    const initInstallStack = async () => {
        if (addToHomeInstance) return; // already initialized
        if (root.querySelectorAll('.add-to-homescreen-wrapper').length === 0) return;

        // 1. <pwa-install> component
        if (!pwaInstallEl) {
            pwaInstallEl = document.createElement('pwa-install');
            const manifestLink = document.querySelector('link[rel="manifest"]');
            if (manifestLink) pwaInstallEl.setAttribute('manifest-url', manifestLink.href);
            pwaInstallEl.setAttribute('use-local-storage', 'true');
            try { pwaInstallEl.hideDialog(); } catch (e) {}
            document.body.appendChild(pwaInstallEl);
            if (deferredInstallPrompt) pwaInstallEl.externalPromptEvent = deferredInstallPrompt;

            pwaInstallEl.addEventListener('pwa-install-success-event', () => {
                root.querySelectorAll('.add-to-homescreen-wrapper').forEach((w) => markAddedToHomeScreen(w, 'added'));
                try { pwaInstallEl.hideDialog(); } catch (e) {}
            });
            pwaInstallEl.addEventListener('pwa-install-fail-event', () => {
                root.querySelectorAll('.add-to-homescreen-wrapper').forEach((w) => {
                    setAtsStatus(w, 'not_added', 'Installation didn\'t complete. You can try again or add to home screen manually from your browser menu.');
                    const btn = w.querySelector('.add-to-homescreen');
                    if (btn) btn.innerHTML = '<i class="fa fa-plus-square"></i> Add to Home Screen';
                });
                try { pwaInstallEl.hideDialog(); } catch (e) {}
            });
            pwaInstallEl.addEventListener('pwa-user-choice-result-event', (e) => {
                const accepted = e?.detail?.userChoiceResult === 'accepted';
                root.querySelectorAll('.add-to-homescreen-wrapper').forEach((w) => {
                    if (accepted) {
                        markAddedToHomeScreen(w, 'added');
                    } else {
                        setAtsStatus(w, 'not_added', 'Install dismissed. You can add the app to your home screen at any time.');
                        const btn = w.querySelector('.add-to-homescreen');
                        if (btn) btn.innerHTML = '<i class="fa fa-plus-square"></i> Add to Home Screen';
                    }
                });
            });
        }

        // 2. AddToHomeScreen modal — read manifest for app name + icon
        let appName = document.title;
        let appIconUrl = '/apple-touch-icon.png';
        const manifestLink = document.querySelector('link[rel="manifest"]');
        if (manifestLink) {
            try {
                const res = await fetch(manifestLink.href);
                if (res.ok) {
                    const m = await res.json();
                    appName = m.name || m.short_name || appName;
                    if (Array.isArray(m.icons) && m.icons.length) {
                        const big = m.icons.find((ic) => ic.sizes && (ic.sizes.includes('192x192') || ic.sizes.includes('512x512')));
                        appIconUrl = (big && big.src) || appIconUrl;
                    }
                }
            } catch (e) { /* fall back to defaults */ }
        }
        try {
            // assetUrl points at the bundled add-to-homescreen image assets
            // (the lib renders illustrations of "tap Share, then Add"); webpack
            // copies them into build/assets/img/ from node_modules.
            const inDevBuild = (window.formr?.bundle === 'dev-build')
                || /assets\/dev-build\//.test(document.querySelector('script[src*="form.bundle.js"]')?.src || '');
            const assetUrl = inDevBuild ? '/assets/dev-build/assets/img/' : '/assets/build/assets/img/';
            addToHomeInstance = AddToHomeScreen({
                appName,
                appIconUrl,
                maxModalDisplayCount: -1,
                assetUrl,
                displayOptions: { showMobile: true, showDesktop: true },
                allowClose: true,
            });
        } catch (e) {
            console.warn('AddToHomeScreen init failed', e);
        }
    };

    initInstallStack();

    root.querySelectorAll('.add-to-homescreen-wrapper, .form-group.item-add_to_home_screen').forEach((wrapper) => {
        if (isStandaloneDisplayMode()) {
            markAddedToHomeScreen(wrapper, 'already_added');
            return;
        }
        const required = wrapper.closest('.form-group')?.classList.contains('required');
        const hidden = wrapper.querySelector('input');
        if (required && hidden && !['added', 'already_added'].includes(hidden.value)) {
            hidden.setCustomValidity('Please add this study to your home screen to continue.');
        }
        const btn = wrapper.querySelector('button.add-to-homescreen, .add-to-homescreen');
        if (!btn) return;
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            if (btn.disabled) return;
            await initInstallStack(); // ensure instances exist (idempotent)

            // Native prompt path: if the browser queued a beforeinstallprompt,
            // hand off to the pwa-install web component which fires the
            // canonical install dialog and dispatches the success/fail events
            // we listened for above.
            if (deferredInstallPrompt && pwaInstallEl) {
                setAtsStatus(wrapper, 'prompted', 'Preparing installation…');
                btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing…';
                try { pwaInstallEl.showDialog(); } catch (err) {
                    console.warn('pwa-install showDialog failed', err);
                }
                return;
            }

            // No native prompt — fall back to AddToHomeScreen's platform-
            // specific modal (iOS Safari guidance, Chrome Android prompt, etc).
            if (addToHomeInstance) {
                try {
                    const result = addToHomeInstance.show();
                    if (!result || !result.canBeStandAlone) {
                        setAtsStatus(wrapper, 'no_support',
                            'Your browser doesn\'t support adding this study to the home screen. Try Safari (iOS), Chrome on Android, or Edge on desktop.');
                    } else {
                        setAtsStatus(wrapper, 'prompted', 'Follow the on-screen instructions to add this study to your home screen.');
                    }
                } catch (err) {
                    console.warn('AddToHomeScreen.show failed', err);
                    setAtsStatus(wrapper, 'not_prompted', 'Could not start the install flow. Try a supported browser.');
                }
                return;
            }

            // Last resort: tell the participant to do it manually.
            setAtsStatus(wrapper, 'not_prompted',
                'Your browser hasn\'t offered an install prompt. Try a supported browser (Chrome on Android, Edge on desktop, Safari on iOS).');
        });
    });

    // --- PWA: PushNotification item ---
    // Subscribe via the SW's pushManager + window.vapidPublicKey, then POST
    // the subscription JSON to /{run}/ajax_save_push_subscription. Hidden
    // input tracks state across reloads. On iOS this only works inside the
    // installed PWA (Safari 16.4+); we surface a helpful error message
    // when subscribing fails for that reason.
    const urlBase64ToUint8Array = (base64String) => {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(base64);
        const arr = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
        return arr;
    };

    const savePushSubscriptionToServer = async (subscription) => {
        if (!runUrl) return false;
        const fd = new FormData();
        fd.append('subscription', JSON.stringify(subscription));
        try {
            const res = await fetch(runUrl + 'ajax_save_push_subscription', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: fd,
            });
            const body = await res.json().catch(() => null);
            return !!(body && body.success);
        } catch (e) {
            console.error('save push subscription failed', e);
            return false;
        }
    };

    // PushNotification_Item::validateInput accepts: 'not_requested',
    // 'not_supported', 'permission_denied' (only when item is optional), or
    // a valid subscription JSON (endpoint + keys.p256dh + keys.auth) for
    // required items. We write the full subscription JSON on success so the
    // server-side store has it, mirroring v1 behaviour.
    const markPushSubscribed = (wrapper, subscription) => {
        const hidden = wrapper.querySelector('input');
        const status = wrapper.querySelector('.status-message');
        const btn = wrapper.querySelector('button.push-notification-permission, .push-notification-permission:not(input)');
        if (hidden) {
            hidden.value = JSON.stringify(subscription);
            hidden.setCustomValidity('');
        }
        if (status) status.textContent = 'Push notifications are enabled.';
        if (btn) {
            btn.disabled = true;
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-success');
            btn.innerHTML = '<i class="fa fa-check"></i> Enabled';
        }
        wrapper.closest('.form-group')?.classList.add('formr_answered');
    };

    root.querySelectorAll('.push-notification-wrapper, .form-group.item-push_notification').forEach((wrapper) => {
        // The hidden input shares class `push-notification-permission` with
        // the visible <button> (server quirk); pick the input by tag/type.
        const hidden = wrapper.querySelector('input[type="text"]');
        const status = wrapper.querySelector('.status-message');
        const btn = wrapper.querySelector('button.push-notification-permission');
        const required = wrapper.closest('.form-group')?.classList.contains('required');
        if (required && hidden && hidden.value === 'not_requested') {
            hidden.setCustomValidity('Please allow push notifications to continue.');
        }
        if (!('Notification' in window) || !('serviceWorker' in navigator) || !window.vapidPublicKey) {
            if (status) {
                status.textContent = !window.vapidPublicKey
                    ? 'Push notifications are not configured for this study.'
                    : 'Your browser does not support push notifications. On iPhone/iPad, install this study to your home screen first (iOS 16.4+).';
            }
            if (hidden) hidden.value = 'not_supported';
            return;
        }
        // Already subscribed? short-circuit.
        navigator.serviceWorker.ready.then((reg) => reg.pushManager.getSubscription()).then((sub) => {
            if (sub) markPushSubscribed(wrapper, sub);
        }).catch(() => {});
        if (!btn) return;
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            try {
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    if (hidden) hidden.value = 'permission_denied';
                    if (status) status.textContent = 'Notification permission was denied. You can change this in your browser settings.';
                    return;
                }
                const reg = await navigator.serviceWorker.ready;
                const existing = await reg.pushManager.getSubscription();
                const sub = existing || await reg.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(window.vapidPublicKey),
                });
                const saved = await savePushSubscriptionToServer(sub);
                if (saved) {
                    markPushSubscribed(wrapper, sub);
                } else if (status) {
                    status.textContent = 'Subscription created locally but could not be saved on the server. Please retry.';
                }
            } catch (err) {
                console.error('push subscription failed', err);
                if (status) {
                    status.textContent = isIOSDevice() && !isStandaloneDisplayMode()
                        ? 'On iPhone/iPad, push notifications only work after you install this study to your home screen and reopen it from there (iOS 16.4+).'
                        : 'Could not subscribe to push notifications. Please try again or check your browser settings.';
                }
            }
        });
    });

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

    // --- Reactive showif via Alpine (Phase 3) ---
    // Item.php emits `data-showif="<transpiled-js>"` on items whose admin-authored
    // `showif` column resolved server-side as visible. We promote that attribute
    // to Alpine's `x-showif` directive (registered at module level) and add
    // `x-data="fmrForm"` on the form so Alpine has a reactive scope. The
    // component exposes one top-level reactive field per input name plus the
    // is.na/answered/contains/… helper set; Alpine handles dep-tracking + re-
    // evaluation, so we don't maintain a bespoke evaluator or input listeners.
    root.querySelectorAll('[data-showif]').forEach((el) => {
        const expr = el.getAttribute('data-showif');
        if (expr) el.setAttribute('x-showif', expr);
        el.removeAttribute('data-showif');
    });
    if (!root.hasAttribute('x-data')) {
        root.setAttribute('x-data', 'fmrForm');
    }

    // collectAnswers remains as a helper for r-call/fill POST payloads. It
    // reads the DOM fresh each call (same contract as before) rather than
    // snapshotting Alpine state — Alpine and the DOM agree at event time.
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

    // --- r-call showif (Phase 3) ---
    // Items the admin opted into server-side R via `r(...)` carry
    // `data-fmr-r-call="{id}"`. When any input changes, POST the current
    // answers + call_id to /form-r-call and let the server evaluate; apply
    // visibility from the response. Debounced + seq-guarded so rapid typing
    // doesn't stack requests or let stale responses override fresh ones.
    const rcallItems = Array.from(root.querySelectorAll('[data-fmr-r-call]'));
    if (rcallItems.length > 0) {
        const rcallUrl = root.getAttribute('data-rcall-url');
        const rcallSeqs = new Map();       // el -> latest seq #
        const rcallLastArgs = new Map();   // el -> last JSON string sent, for dedup
        let rcallPendingTimer = null;

        const applyRCallResult = (el, visible) => {
            el.classList.toggle('hidden', !visible);
            el.toggleAttribute('data-fmr-hidden', !visible);
            el.style.display = visible ? '' : 'none';
            el.querySelectorAll('input, select, textarea').forEach((inp) => {
                if (inp.name && !inp.name.startsWith('_item_views')) {
                    inp.disabled = !visible;
                }
            });
        };

        const triggerRCalls = () => {
            if (!rcallUrl) return;
            const answers = collectAnswers();
            const answersKey = JSON.stringify(answers);
            rcallItems.forEach((el) => {
                const callId = Number(el.getAttribute('data-fmr-r-call'));
                if (!callId) return;
                // Skip duplicate: same answers → same visibility, no need to re-POST.
                if (rcallLastArgs.get(el) === answersKey) return;
                rcallLastArgs.set(el, answersKey);
                const seq = (rcallSeqs.get(el) || 0) + 1;
                rcallSeqs.set(el, seq);
                fetch(rcallUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ call_id: callId, answers }),
                }).then((r) => (r.ok ? r.json() : null))
                  .then((data) => {
                      if (!data || rcallSeqs.get(el) !== seq) return; // stale
                      if (typeof data.result === 'boolean') applyRCallResult(el, data.result);
                  }).catch(() => { /* leave visibility as-is on failure */ });
            });
        };

        const scheduleRCalls = () => {
            if (rcallPendingTimer !== null) clearTimeout(rcallPendingTimer);
            rcallPendingTimer = setTimeout(() => { rcallPendingTimer = null; triggerRCalls(); }, 300);
        };

        root.addEventListener('input', scheduleRCalls);
        root.addEventListener('change', scheduleRCalls);
        // Fire once at load so any items hidden server-side by initial-empty-answers
        // can re-evaluate against whatever answers actually exist in the DOM.
        triggerRCalls();
    }

    // Deferred-fill at initial load was the previous model's per-item
    // /form-fill resolver. Under the page-scoped model:
    //   - First-page items have their r(...) values resolved server-side at
    //     FormRenderer time (the inner R is substituted into $item->value
    //     and runs through processDynamicValuesAndShowIfs). The server
    //     emits the resolved scalar in the input's value attribute, so the
    //     client doesn't need to ask.
    //   - Later-page items have data-fmr-fill-id but a blank value; they
    //     resolve via /form-render-page when the participant transitions
    //     to that page (resolveAndSubstitutePage above).
    // The /form-fill endpoint itself is still live and useful for one-off
    // live re-triggers (e.g. an admin-driven retrigger button) — we just
    // don't fire it on every page-load anymore.

    // --- Button groups (Phase 2) ---
    // v1's ButtonGroup.js leans on jQuery + webshim.addShadowDom to keep a
    // visible .btn-group in sync with hidden radios/checkboxes inside
    // .mc-table.js_hidden. In v2 we do this vanilla:
    //   1. Click on <button data-for="inputId"> → toggle the paired input.
    //   2. For radio groups (.btn-radio), clicking an already-checked button
    //      is a no-op (native radios don't untoggle); clicking a new one
    //      clears siblings and fires change.
    //   3. For checkbox/check groups, each click toggles independently.
    //   4. On the hidden input's `invalid` event (native constraint validation
    //      fires even for display:none inputs), flag the wrapper .form-group
    //      .is-invalid so the visible button group picks up the CSS outline,
    //      and append a BS5-style .invalid-feedback message. Clears on any
    //      subsequent input change.
    const initButtonGroups = () => {
        const wrappers = root.querySelectorAll('.form-group.btn-radio, .form-group.btn-checkbox, .form-group.btn-check');
        wrappers.forEach((wrapper) => {
            const kind = wrapper.classList.contains('btn-checkbox') ? 'multi'
                : wrapper.classList.contains('btn-check') ? 'check' : 'radio';
            const btnGroup = wrapper.querySelector('.btn-group');
            if (!btnGroup) return;
            const buttons = Array.from(btnGroup.querySelectorAll('.btn[data-for]'));
            if (!buttons.length) return;

            // Initial state: mirror existing checked state onto the buttons.
            const resolveInput = (btn) => wrapper.querySelector('#' + CSS.escape(btn.getAttribute('data-for')));
            buttons.forEach((btn) => {
                const input = resolveInput(btn);
                if (input && input.checked) btn.classList.add('btn-checked');
            });

            buttons.forEach((btn) => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    const input = resolveInput(btn);
                    if (!input) return;
                    if (kind === 'radio') {
                        if (input.checked) return; // native radios don't untoggle
                        buttons.forEach((b) => b.classList.remove('btn-checked'));
                        // Uncheck siblings manually — the hidden radios share a
                        // `name` so browsers sync them, but fire change on the
                        // newly-selected one to wake up showif listeners.
                        input.checked = true;
                        btn.classList.add('btn-checked');
                    } else {
                        const nowChecked = !input.checked;
                        input.checked = nowChecked;
                        btn.classList.toggle('btn-checked', nowChecked);
                    }
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                });
            });

            // Native constraint-validation feedback. The hidden inputs live
            // under .js_hidden (display:none) which kills the default tooltip
            // position, so we surface the error inline at the button group.
            const feedbackId = 'fmr-btn-feedback-' + (btnGroup.id || Math.random().toString(36).slice(2, 8));
            wrapper.querySelectorAll('input[required]').forEach((inp) => {
                inp.addEventListener('invalid', (e) => {
                    // Don't preventDefault — let the browser's "first invalid"
                    // navigation still pick this wrapper; it scrolls the item
                    // into view even when the input itself is hidden.
                    wrapper.classList.add('is-invalid');
                    if (!wrapper.querySelector('.fmr-btn-feedback')) {
                        const fb = document.createElement('div');
                        fb.className = 'invalid-feedback fmr-btn-feedback d-block';
                        fb.id = feedbackId;
                        fb.textContent = inp.validationMessage || 'Please choose an option.';
                        btnGroup.insertAdjacentElement('afterend', fb);
                    }
                }, true);
            });

            // Clear invalid state on any change (user-driven or programmatic).
            wrapper.addEventListener('change', () => {
                wrapper.classList.remove('is-invalid');
                const fb = wrapper.querySelector('.fmr-btn-feedback');
                if (fb) fb.remove();
            });
        });
    };
    initButtonGroups();

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

    // Tom-select on select_or_add_one / select_or_add_multiple items. These
    // render as plain <input class="select2add"> with the choice set in
    // data-select2add (JSON [{id,text}, ...]). v1 used select2; v2 wires
    // tom-select directly on the <input> so admins can add free-form choices
    // unless the form-group opts out (.network_select / .ratgeber_class /
    // .cant_add_choice — these are designed for selecting existing entities
    // only, e.g. a network study where participants pick each other).
    root.querySelectorAll('input.select2add').forEach((inp) => {
        if (!inp.name || inp.dataset.tomSelectInit === '1') return;
        inp.dataset.tomSelectInit = '1';
        let options = [];
        try {
            const raw = inp.dataset.select2add;
            if (raw) {
                const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
                // v1 packs comma-separated choice strings into single {id,text}
                // entries; flatten here so each comma-separated token becomes
                // its own option and tom-select can search them individually.
                parsed.forEach((opt) => {
                    const tokens = String(opt.id || '').split(',');
                    tokens.forEach((tok) => {
                        const trimmed = tok.trim();
                        if (trimmed) options.push({ id: trimmed, text: trimmed });
                    });
                });
            }
        } catch (e) {
            console.warn('select_or_add: bad data-select2add JSON for', inp.name, e);
        }
        const multiple = inp.dataset.select2multiple === '1' || inp.dataset.select2multiple === 1;
        const maxItems = parseInt(inp.dataset.select2maximumSelectionSize || '0', 10);
        const maxLength = parseInt(inp.dataset.select2maximumInputLength || '0', 10);
        const wrapper = inp.closest('.form-group');
        const lockedToChoices = !!(wrapper && (
            wrapper.classList.contains('network_select') ||
            wrapper.classList.contains('ratgeber_class') ||
            wrapper.classList.contains('cant_add_choice')
        ));
        // Seed pre-existing values (back-nav / fill). v1 multi stored as
        // comma-separated; v1 multi getReply re-joined with \n server-side.
        const existingRaw = inp.value || '';
        const items = existingRaw
            ? (multiple ? existingRaw.split(/[,\n]/).map((s) => s.trim()).filter(Boolean) : [existingRaw.trim()])
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
                maxItems: multiple ? (maxItems > 0 ? maxItems : null) : 1,
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

    // Initial page from ?page=N. The query-string `page` refers to the
    // server-side `survey_items_display.page` number (which is what shows up
    // in data-fmr-page and what the submit response returns via next_page).
    // When a participant reloads mid-form the server only includes the pages
    // they haven't answered yet, so the page-number ≠ array index. Look up
    // by dataset match; fall back to first page if the requested page isn't
    // in the DOM.
    const indexForPageParam = (p) => {
        if (!p) return 0;
        const idx = pages.findIndex((el) => Number(el.dataset.fmrPage) === p);
        return idx === -1 ? 0 : idx;
    };
    const paramPage = Number(new URLSearchParams(window.location.search).get('page'));
    showPage(indexForPageParam(paramPage));

    window.addEventListener('popstate', () => {
        const p = Number(new URLSearchParams(window.location.search).get('page'));
        showPage(indexForPageParam(p));
    });

    // --- RequestCookie item (functional cookie consent) ---
    // Minimal vanilla port of PWAInstaller.js::initializeRequestCookie.
    // Server (RequestCookie_Item) renders a .request-cookie-wrapper with a
    // hidden <input>, a <button.request-cookie>, and a .status-message.
    // When the participant has already granted functional consent, the
    // wrapper needs to mark itself answered; otherwise click opens the
    // consent dialog via the global showPreferences() if exposed.
    const hasFunctionalConsent = () => {
        const row = document.cookie.split('; ').find((r) => r.startsWith('formrcookieconsent='));
        if (!row) return false;
        try {
            const val = decodeURIComponent(row.split('=')[1] || '');
            return val.indexOf('"necessary","functionality"') !== -1;
        } catch (e) { return false; }
    };
    const markRequestCookieAnswered = (wrapper) => {
        const hidden = wrapper.querySelector('input');
        const status = wrapper.querySelector('.status-message');
        const btn = wrapper.querySelector('button.request-cookie');
        if (hidden) { hidden.value = 'consent_given'; hidden.setCustomValidity(''); }
        if (status) status.textContent = 'Functional cookies enabled. You can continue.';
        if (btn) {
            btn.disabled = true;
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-success');
            btn.innerHTML = '<i class="fa fa-check"></i> Enabled';
        }
        const formGroup = wrapper.closest('.form-group');
        if (formGroup) formGroup.classList.add('formr_answered');
    };
    root.querySelectorAll('.request-cookie-wrapper').forEach((wrapper) => {
        const hidden = wrapper.querySelector('input');
        const btn = wrapper.querySelector('button.request-cookie');
        const required = wrapper.closest('.form-group')?.classList.contains('required');
        if (required && hidden) {
            hidden.setCustomValidity('Please enable functional cookies to continue.');
        }
        if (hasFunctionalConsent()) {
            markRequestCookieAnswered(wrapper);
            return;
        }
        if (btn) {
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                if (typeof window.showPreferences === 'function') window.showPreferences();
            });
        }
        // Poll for consent granted in another tab / via the footer dialog.
        const poll = setInterval(() => {
            if (hasFunctionalConsent()) {
                markRequestCookieAnswered(wrapper);
                clearInterval(poll);
            }
        }, 1000);
    });

    // --- RequestPhone item (mobile-device affirmation) ---
    // Pared-down port of initializeRequestPhone: the server already marks the
    // item answered on mobile UAs (RequestPhone_Item::setMoreOptions sets
    // no_user_input_required=true). On desktop we surface a short status
    // message; full QR-code generation is deferred (part of the PWA wiring
    // that still lives in PWAInstaller.js).
    const isMobileUA = /Mobi|Android|iPhone|iPad|iPod|BlackBerry|webOS|IEMobile|Opera Mini/i.test(navigator.userAgent);
    root.querySelectorAll('.request-phone-wrapper').forEach((wrapper) => {
        const hidden = wrapper.querySelector('input');
        const status = wrapper.querySelector('.status-message');
        const required = wrapper.closest('.form-group')?.classList.contains('required');
        if (required && hidden && !hidden.value) {
            hidden.setCustomValidity('Please complete this required step before continuing.');
        }
        if (isMobileUA) {
            if (hidden && !hidden.value) hidden.value = 'is_phone';
            if (hidden) hidden.setCustomValidity('');
            if (status) status.textContent = 'You are already on a mobile device. You can continue.';
            const formGroup = wrapper.closest('.form-group');
            if (formGroup) formGroup.classList.add('formr_answered');
        } else if (status) {
            status.textContent = 'Open this form on your phone to continue. (QR-code / install assistant lives in the v1 participant bundle; v2 port is pending — see plan_form_v2.md §8 P1.)';
        }
    });

    // --- Monkey bar buttons (admin preview mode) ---
    // v1 wired these through jQuery+FormMonkey+select2. v2 provides a light
    // vanilla port: enough to eyeball a form, not a full parity reimplementation.
    // The monkey bar lives outside `.fmr-form-v2` (sibling via Run::exec), so
    // query against document, not root.
    const monkeyBar = document.querySelector('.monkey_bar');
    if (monkeyBar) {
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
                    // Force display override — the page-scope `.hidden_debug_message`
                    // rule has `!important` so we need to counteract it inline when toggled on.
                    if (!el.classList.contains('hidden')) {
                        el.style.display = 'block';
                    } else {
                        el.style.display = '';
                    }
                });
            });
        }
        // "Monkey mode" — auto-fill every visible input on the current page.
        // Minimal port of v1's FormMonkey: picks plausible defaults by type,
        // first option for selects/radio groups, a random 1..max for ranges.
        const monkeyBtn = monkeyBar.querySelector('button.monkey');
        if (monkeyBtn) {
            monkeyBtn.disabled = false;
            monkeyBtn.addEventListener('click', (e) => {
                e.preventDefault();
                fillPageWithMonkey();
            });
        }
    }

    function fillPageWithMonkey() {
        const page = pages[currentIndex];
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
                const d = new Date(today);
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
        const visibleItems = page.querySelectorAll('.form-group.form-row:not(.hidden):not(.item-submit):not(.item-note):not(.item-block):not(.item-note_iframe)');
        visibleItems.forEach((group) => {
            // Already answered? Skip.
            const realInputs = [...group.querySelectorAll('input[name], select[name], textarea[name]')]
                .filter((i) => i.name && !i.name.startsWith('_item_views') && !i.disabled);
            if (realInputs.length === 0) return;
            // Radio / checkbox groups: pick the first non-hidden option.
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
            // Selects (incl. tom-select wrapped)
            const select = realInputs.find((i) => i.tagName === 'SELECT');
            if (select) {
                if (select.tomselect) {
                    const opts = Object.keys(select.tomselect.options || {});
                    if (opts.length) select.tomselect.setValue(opts[0]);
                } else if (select.options.length) {
                    select.selectedIndex = Math.max(1, 0); // skip blank if present
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                }
                return;
            }
            // Tom-select-wrapped <input.select2add>
            const addable = realInputs.find((i) => i.classList.contains('select2add') && i.tomselect);
            if (addable) {
                const opts = Object.keys(addable.tomselect.options || {});
                if (opts.length) addable.tomselect.setValue(opts[0]);
                else addable.tomselect.addOption({ id: 'monkey', text: 'monkey' }) && addable.tomselect.setValue('monkey');
                return;
            }
            // Plain text-ish inputs + textareas: fill the first non-hidden one.
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
}

// Alpine component + directive registrations. Done at module load so they are
// in place before Alpine.start() scans the DOM. The form bundle intentionally
// has a single Alpine scope — `fmrForm` on the outer `<form>` — and a single
// custom directive (`x-showif`). Everything else rides Alpine's built-ins.
Alpine.data('fmrForm', () => ({
    // Helpers — accessible inside any `x-showif` expression as `isNA(X)`,
    // `answered(X)`, etc. Alpine binds `this` to $data when evaluating, so
    // method delegates (`answered` → `this.isNA`) resolve correctly.
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
        // Register one reactive top-level key per named input. Alpine's `reactive`
        // (Vue 3 Proxy) tracks new keys assigned to `this`, so showif expressions
        // like `trigger == 'yes'` resolve against `$data.trigger` after the first
        // assignment. Empty/unchecked normalizes to `null` so helpers see a
        // single "not answered" shape.
        const inputs = this.$root.querySelectorAll('input[name], select[name], textarea[name]');
        inputs.forEach((inp) => {
            const raw = inp.name || '';
            if (raw.startsWith('_item_views')) return;
            const key = raw.endsWith('[]') ? raw.slice(0, -2) : raw;
            if (!(key in this)) this[key] = null;
        });
        // Populate initial values (checked radios, text, selects, etc.).
        inputs.forEach((inp) => this._syncInput(inp));
        this.$root.addEventListener('input', (e) => this._syncInput(e.target));
        this.$root.addEventListener('change', (e) => this._syncInput(e.target));
    },

    _syncInput(inp) {
        const raw = inp.name || '';
        if (!raw || raw.startsWith('_item_views')) return;
        if (inp.disabled) return; // showif-hidden inputs keep their prior value
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

// Custom `x-showif` directive. Same contract as `x-show` but also
// (a) toggles the Bootstrap `.hidden` class (ships display:none !important,
// otherwise a parent/ancestor's `.hidden` wins), (b) disables inputs in the
// hidden region so native `required` doesn't block submit and their values
// don't get sent, and (c) rewrites v1's `is.na(X)` transpile output from
// `(typeof(X) === 'undefined')` to `isNA(X)` since our reactive state
// normalizes empty/unchecked to `null`, not `undefined`.
//
// Expression robustness: the transpiled R often carries `//js_only` or `//`
// line comments, which otherwise swallow our wrapping closing paren and
// produce a SyntaxError at `new AsyncFunction()` time. Strip comments first.
// Expressions can also reference names not in `$data` (run-level variables
// like `ran_group`, or items from other pages not present on this form);
// wrap the evaluation in a runtime try/catch so ReferenceError defaults to
// undefined (i.e. "show") instead of noisily blowing up every keystroke.
Alpine.directive('showif', (el, { expression }, { evaluateLater, effect, cleanup }) => {
    const cleaned = (expression || '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/\/\/.*$/gm, '')
        .trim();
    if (!cleaned) return; // no showif → leave the server-side state as-is
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
    } catch (e) {
        // Expression couldn't compile at all (unmatched parens, stray tokens
        // post-comment-strip, etc.). Fall back to visible so the participant
        // can still see the item — better than a silently-hidden field that
        // never reveals.
        applyVisibility(true);
        return;
    }
    effect(() => {
        getValue((result) => {
            // Runtime failure (our inner try/catch returns undefined, or Alpine
            // itself swallowed something): treat as "show".
            const visible = (result === undefined) ? true : !!result;
            applyVisibility(visible);
        });
    });
});

window.Alpine = Alpine;

document.addEventListener('DOMContentLoaded', () => {
    initForm();
    Alpine.start();
});
