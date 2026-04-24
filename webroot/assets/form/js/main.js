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
    const syncUrl = root.dataset.syncUrl;

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

    const drainQueue = async () => {
        if (!syncUrl) return;
        let entries;
        try { entries = await queueGetAll(); } catch (e) { return; }
        if (!entries || !entries.length) { hideQueueBanner(); return; }
        entries.sort((a, b) => (a.client_ts || '').localeCompare(b.client_ts || ''));
        showQueueBanner(`Syncing ${entries.length} queued submission${entries.length === 1 ? '' : 's'}…`, 'warning');
        for (const entry of entries) {
            let res;
            try {
                res = await fetch(syncUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(entry),
                });
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
        // Offline / server-5xx → queue the JSON-path submission and keep
        // the participant moving. File submissions can't be queued yet;
        // surface the error so they can retry manually.
        if (isTransientFailure(netErr, res)) {
            if (useMultipart || !syncUrl) {
                console.error('page-submit offline (multipart or no sync url)', netErr || res.status);
                window.alert('You seem to be offline. File uploads can\'t be queued — please try again.');
                return;
            }
            const entry = {
                uuid: genUuid(),
                page: pageNum,
                data: payload.data,
                item_views: payload.item_views,
                // MySQL DATETIME rejects ISO-8601 with ".sssZ" — same gotcha as
                // item_shown timestamps. Use the space-separated format.
                client_ts: mysqlDatetime(),
            };
            try { await queueAdd(entry); } catch (e) { console.error('enqueue failed', e); }
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

    // Standard-library helpers made available inside every showif expression.
    // Mirrors the ergonomic R-isms admins are used to so JS-authored showifs
    // (`//js_only` or future native-JS column) can lean on them, and so the
    // R-transpile output can call them instead of emitting fragile inline
    // coercions. `isNA` is the canonical "not answered" check — covers the
    // `null` values `collectAnswers` normalizes empty/unchecked to, plus `""`,
    // `undefined`, empty arrays.
    const showifHelpers = {
        isNA: (v) => v === null || v === undefined || v === ''
            || (Array.isArray(v) && v.length === 0) || (typeof v === 'number' && isNaN(v)),
        answered: (v) => !showifHelpers.isNA(v),
        contains: (haystack, needle) => {
            if (showifHelpers.isNA(haystack)) return false;
            if (Array.isArray(haystack)) return haystack.includes(needle);
            return String(haystack).indexOf(String(needle)) > -1;
        },
        containsWord: (haystack, word) => {
            if (showifHelpers.isNA(haystack)) return false;
            const re = new RegExp('\\b' + String(word).replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b');
            return re.test(String(haystack));
        },
        startsWith: (haystack, prefix) => {
            if (showifHelpers.isNA(haystack)) return false;
            return String(haystack).startsWith(String(prefix));
        },
        endsWith: (haystack, suffix) => {
            if (showifHelpers.isNA(haystack)) return false;
            return String(haystack).endsWith(String(suffix));
        },
        last: (arr) => Array.isArray(arr) && arr.length > 0 ? arr[arr.length - 1] : arr,
    };

    // Compile each data-showif once. On failure, the item is treated as visible.
    const compileShowif = (expr) => {
        let cleaned = (expr || '').replace(/\/\*[\s\S]*?\*\/|\/\/.*/g, '').trim();
        if (!cleaned) return () => true;
        // v1's Item.php regex-transpile emits `(typeof(X) === 'undefined')` for
        // R `is.na(X)`, but `collectAnswers` normalizes empty/unchecked inputs
        // to `null`, not `undefined` — so that check silently never fires.
        // Route it through `isNA(X)` (same identifier set v1 emits: letters,
        // digits, underscore, quotes) so R-authored `is.na` actually evaluates.
        cleaned = cleaned.replace(
            /\(\s*typeof\(\s*([A-Za-z0-9_'"]+)\s*\)\s*===\s*['"]undefined['"]\s*\)/g,
            'isNA($1)'
        );
        try {
            // eslint-disable-next-line no-new-func
            const fn = new Function('context', 'with (context) { return (' + cleaned + '); }');
            return (ctx) => {
                try { return !!fn(Object.assign({}, showifHelpers, ctx)); } catch (e) { return true; /* on failure show */ }
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

    // --- Deferred fill for r(...)-wrapped `value` columns (Phase 4) ---
    // Items whose `value` column was r(...)-wrapped carry `data-fmr-fill-id`.
    // FormRenderer cleared the inline value and routes evaluation through
    // /form-fill; we POST {call_id, answers} once on load, set the input's
    // value from the response, fire a `change` so showifs re-evaluate, and
    // strip the pending-state class. Not re-fetched on subsequent input
    // changes: a deferred fill is the admin's pre-computed default, not a
    // reactive formula. Admins who need reactivity should wire showif/r-call.
    const fillItems = Array.from(root.querySelectorAll('[data-fmr-fill-id]'));
    if (fillItems.length > 0) {
        const fillUrl = root.getAttribute('data-fill-url');
        fillItems.forEach((wrapper) => wrapper.classList.add('fmr-fill-pending'));
        const applyFill = (wrapper, value) => {
            const input = wrapper.querySelector('input[name], textarea[name], select[name]');
            if (input && (input.value === '' || input.value == null)) {
                input.value = value == null ? '' : String(value);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
            wrapper.classList.remove('fmr-fill-pending');
        };
        const markFillError = (wrapper, msg) => {
            wrapper.classList.remove('fmr-fill-pending');
            wrapper.classList.add('fmr-fill-error');
            const feedback = document.createElement('div');
            feedback.className = 'invalid-feedback fmr-fill-feedback d-block';
            feedback.textContent = String(msg || 'Failed to load computed value.');
            const anchor = wrapper.querySelector('.controls, .form-group') || wrapper;
            anchor.appendChild(feedback);
        };
        if (fillUrl) {
            const answers = collectAnswers();
            fillItems.forEach((wrapper) => {
                const callId = Number(wrapper.getAttribute('data-fmr-fill-id'));
                if (!callId) return;
                fetch(fillUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ call_id: callId, answers }),
                }).then(async (r) => ({ ok: r.ok, body: await r.json().catch(() => null) }))
                  .then(({ ok, body }) => {
                      if (!ok || !body) {
                          markFillError(wrapper, body && body.error);
                          return;
                      }
                      if (typeof body.error === 'string') {
                          markFillError(wrapper, body.error);
                          return;
                      }
                      applyFill(wrapper, body.value);
                  }).catch(() => markFillError(wrapper, 'Network error.'));
            });
        }
    }

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
