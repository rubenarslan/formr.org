// form_v2 participant bundle.
// Single-page AJAX form: all pages rendered server-side in one document,
// client-side navigation between `<section data-fmr-page>` wrappers, page-at-
// a-time AJAX submission. Alpine drives reactive `showif` (`x-showif`
// directive + `fmrForm` component); everything else (r-call debounce, offline
// queue, button-group wiring, file uploads, geopoint) is plain DOM/fetch.

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
        // surface the error so they can retry manually. When the study has
        // opted out of offline mode, syncUrl is empty and we bubble the
        // transient failure as a hard error rather than persisting to IDB.
        if (isTransientFailure(netErr, res)) {
            if (useMultipart || !syncUrl) {
                console.error('page-submit offline (multipart or offline queue disabled)', netErr || res.status);
                const msg = useMultipart
                    ? 'You seem to be offline. File uploads can\'t be queued — please try again.'
                    : 'Your submission could not be sent. Please check your connection and try again.';
                window.alert(msg);
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
