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

import { mysqlDatetime } from './lib/time.js';
import { genUuid } from './lib/uuid.js';
import { queueAdd, queueGetAll, queueDelete, buildSyncFormData, isTransientFailure } from './offline/queue.js';
import { initMediaRecorders } from './items/recorders.js';
import { initButtonGroups } from './items/button-groups.js';
import { initGeopoint } from './items/geopoint.js';
import { initTomSelects } from './items/tom-select.js';
import { initRequestCookie } from './items/request-cookie.js';
import { initRequestPhone } from './items/request-phone.js';
import { initAdminPreview } from './items/admin-preview.js';
import {
    clearCustomValidity,
    findErrorTarget,
    applyErrors,
    validatePageAndShowFeedback,
    installFeedbackClearer,
} from './validation/feedback.js';
import {
    registerFmrForm,
    registerXShowif,
    promoteShowifAttributes,
} from './showif/alpine.js';

function initForm() {
    const root = document.querySelector('.fmr-form-v2');
    if (!root) return;

    const pages = Array.from(root.querySelectorAll('[data-fmr-page]'));
    if (pages.length === 0) return;

    let currentIndex = 0;

    const progressBar = root.querySelector('[data-fmr-progress-bar]');
    const progressLabel = root.querySelector('[data-fmr-progress-label]');


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

    // clearCustomValidity / findErrorTarget / applyErrors / validatePageAndShowFeedback /
    // installFeedbackClearer live in ./validation/feedback.js (imports above).
    // initGeopoint lives in ./items/geopoint.js (imports above) — called from initForm below.

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
    // IDB plumbing + multipart serialization + isTransientFailure live in
    // ./offline/queue.js (imports above). genUuid in ./lib/uuid.js. The
    // banner + drain orchestration stays here because it pokes `root` and
    // window navigation directly.
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

    // validatePageAndShowFeedback + the input-change listener that clears
    // stale `.fmr-invalid-feedback` live in ./validation/feedback.js.
    installFeedbackClearer(root);

    const submitPage = async () => {
        const page = pages[currentIndex];
        clearCustomValidity(page);
        if (!validatePageAndShowFeedback(page)) {
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
        // Per-instance config (window.formr.form_v2.offline_blob_max_mb,
        // populated by Controller::getJsConfig from
        // $settings['form_v2_offline_blob_max_mb']). Default 10MB. Admins
        // can raise via config/settings.php for long-form audio/video.
        const QUEUE_FILE_SIZE_CAP_MB = (window.formr?.form_v2?.offline_blob_max_mb) || 10;
        const QUEUE_FILE_SIZE_CAP = QUEUE_FILE_SIZE_CAP_MB * 1024 * 1024;
        if (isTransientFailure(netErr, res)) {
            const oversizedFile = useMultipart
                ? fileInputs.find((inp) => inp.files[0] && inp.files[0].size > QUEUE_FILE_SIZE_CAP)
                : null;
            if (!syncUrl || oversizedFile) {
                console.error('page-submit offline (offline queue disabled or file too large)', netErr || (res && res.status));
                const msg = oversizedFile
                    ? `Submission too large to queue offline (${(oversizedFile.files[0].size / 1024 / 1024).toFixed(1)} MB, limit ${QUEUE_FILE_SIZE_CAP_MB} MB). Please retry when you're back online.`
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

            // v1's exact ordering, ported (PWAInstaller.js around the
            // initializePWAInstaller click handler):
            //   1. If beforeinstallprompt was queued → hand off to <pwa-install>.
            //      That's the "real" install path on Chrome/Edge desktop and
            //      Chrome on Android.
            //   2. Otherwise → AddToHomeScreen.show() renders its polished
            //      cross-platform modal (handles iOS Safari guidance, the
            //      already-standalone case, etc.).
            //   3. If AddToHomeScreen reports `canBeStandAlone: false` (the
            //      browser/OS combo can't auto-install: iOS Chrome,
            //      iOS Firefox, etc.) → ALSO call pwaInstallEl.showDialog()
            //      so the @khmyznikov/pwa-install web component's iOS-Chrome-
            //      specific dialog renders. This is the dialog the user
            //      reported missing in v2 — the "open in Safari" / install-
            //      assist UI for non-Safari iOS browsers lives in this
            //      component, not in AddToHomeScreen alone.
            //   4. If AddToHomeScreen reports `canBeStandAlone: true` (page
            //      is already standalone — rare on a fresh click) → mark as
            //      prompted; the success path will catch the actual install.
            let prompted = false;
            if (deferredInstallPrompt && pwaInstallEl) {
                prompted = true;
                try { pwaInstallEl.showDialog(); } catch (err) {
                    console.warn('pwa-install showDialog failed', err);
                }
            } else if (addToHomeInstance) {
                let result = null;
                try { result = addToHomeInstance.show(); } catch (err) {
                    console.warn('AddToHomeScreen.show failed', err);
                }
                const canBeStandAlone = !!(result && result.canBeStandAlone);
                if (!canBeStandAlone && pwaInstallEl) {
                    // Fall through to the web component's dialog — this is
                    // the path that triggers on iOS Chrome (and Firefox, Edge
                    // on iOS, etc.).
                    try { pwaInstallEl.showDialog(); } catch (err) {
                        console.warn('pwa-install showDialog (fallback) failed', err);
                    }
                    prompted = true;
                } else if (!canBeStandAlone) {
                    // No web component AND can't standalone — last resort.
                    setAtsStatus(wrapper, 'no_support',
                        'Your browser doesn\'t support adding this study to the home screen. Try Safari (iOS), Chrome on Android, or Edge on desktop.');
                } else {
                    prompted = true;
                }
            } else if (pwaInstallEl) {
                // AddToHomeScreen lib failed to load but the web component
                // exists. Use it directly.
                try { pwaInstallEl.showDialog(); prompted = true; } catch (err) {
                    console.warn('pwa-install showDialog (no-ats) failed', err);
                }
            }

            if (prompted) {
                setAtsStatus(wrapper, 'prompted', 'Preparing installation…');
                btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing…';
            } else {
                setAtsStatus(wrapper, 'not_prompted',
                    'Your browser hasn\'t offered an install prompt. Try a supported browser (Chrome on Android, Edge on desktop, Safari on iOS).');
            }
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
    //
    // Mirrors PWAInstaller.js::initializePushNotifications. v1 has been
    // tuned over many iOS / Chrome / Firefox / Edge edge cases (Apple
    // dropping subscriptions silently, iOS 16.4 cutoff, in-browser-but-
    // not-standalone, denied-at-init recovery, etc.) — keeping the same
    // surface area means admins authoring with v1 fixtures get identical
    // participant UX in v2.
    const isIOSCompatibleVersion = () => {
        if (!isIOSDevice()) return true;
        const m = navigator.userAgent.match(/OS (\d+)_(\d+)/);
        if (!m) return false;
        const major = parseInt(m[1], 10), minor = parseInt(m[2], 10);
        return major > 16 || (major === 16 && minor >= 4);
    };

    const sendTestNotification = async (registration) => {
        if (!registration || !registration.showNotification) return false;
        try {
            await registration.showNotification('Test notification', {
                body: 'This is a test notification. If you can see this, notifications are working!',
                tag: 'fmr-test-notification',
            });
            setTimeout(async () => {
                try {
                    const list = await registration.getNotifications({ tag: 'fmr-test-notification' });
                    list.forEach((n) => n.close());
                } catch (e) {}
            }, 5000);
            return true;
        } catch (e) {
            console.error('test notification failed', e);
            return false;
        }
    };

    const renderNotificationHelp = (wrapper) => {
        const ua = navigator.userAgent.toLowerCase();
        let html = '';
        if (/iphone|ipad|ipod/.test(ua)) {
            html = '<ol><li>Open <strong>Settings</strong> → <strong>Notifications</strong>.</li>'
                 + '<li>Find Safari (or your browser) and enable <strong>Allow Notifications</strong>.</li>'
                 + '<li>Make sure <strong>Focus</strong> mode isn\'t blocking notifications.</li>'
                 + '<li>For home-screen apps, check <strong>Settings → Screen Time → Content & Privacy Restrictions</strong>.</li>'
                 + '<li>Reload this page after changing settings.</li></ol>';
        } else if (/android/.test(ua)) {
            html = '<ol><li>Open <strong>Settings → Apps</strong>, find your browser.</li>'
                 + '<li>Tap <strong>Notifications</strong> and ensure they\'re <strong>Allowed</strong>.</li>'
                 + '<li>Check that <strong>Do Not Disturb</strong> isn\'t blocking notifications.</li>'
                 + '<li>Some manufacturers have battery-optimization settings that suppress notifications — disable them for your browser.</li>'
                 + '<li>Try installing this study to your home screen for better notification support.</li>'
                 + '<li>Reload this page after changing settings.</li></ol>';
        } else if (/macintosh/.test(ua)) {
            html = '<ol><li>Open <strong>System Settings → Notifications</strong>.</li>'
                 + '<li>Find your browser and ensure <strong>Allow Notifications</strong> is on.</li>'
                 + '<li>Check that <strong>Do Not Disturb</strong> / <strong>Focus</strong> is off.</li>'
                 + '<li>Reload this page after changing settings.</li></ol>';
        } else if (/windows/.test(ua)) {
            html = '<ol><li>Open <strong>Settings → System → Notifications</strong>.</li>'
                 + '<li>Ensure your browser has notifications turned on.</li>'
                 + '<li>Check that <strong>Focus assist</strong> isn\'t blocking them.</li>'
                 + '<li>Reload this page after changing settings.</li></ol>';
        } else {
            html = '<p>Check your operating system\'s notification settings to make sure your browser is allowed to send notifications.</p>';
        }
        let helpEl = wrapper.querySelector('.fmr-push-help');
        if (!helpEl) {
            helpEl = document.createElement('div');
            helpEl.className = 'fmr-push-help';
            wrapper.querySelector('.status-message')?.after(helpEl);
        }
        helpEl.innerHTML = html;
        const toggleBtn = wrapper.querySelector('.fmr-push-help-toggle');
        if (toggleBtn) {
            toggleBtn.textContent = 'Hide troubleshooting tips';
            toggleBtn.dataset.fmrShown = '1';
        }
    };

    const renderNotificationControls = (wrapper, registration, opts) => {
        const status = wrapper.querySelector('.status-message');
        if (!status) return;
        const showTest = opts.showTestButton !== false;
        const showUnsub = !!opts.showUnsubscribeButton;
        const msg = opts.customMessage || '';
        const extra = opts.additionalContent || '';
        const buttons = [];
        if (showTest) buttons.push('<button type="button" class="btn btn-default fmr-push-test"><i class="fa fa-bell"></i> Test notification</button>');
        if (showUnsub) buttons.push('<button type="button" class="btn btn-warning fmr-push-unsubscribe"><i class="fa fa-bell-slash"></i> Disable notifications</button>');
        buttons.push('<button type="button" class="btn btn-link fmr-push-help-toggle">Show troubleshooting tips</button>');
        status.innerHTML =
            (msg ? `<p>${msg}</p>` : '') +
            extra +
            `<div class="notification-controls btn-group" role="group">${buttons.join('')}</div>`;
        const testBtn = wrapper.querySelector('.fmr-push-test');
        if (testBtn) testBtn.addEventListener('click', async () => { await sendTestNotification(registration); });
        const unsubBtn = wrapper.querySelector('.fmr-push-unsubscribe');
        if (unsubBtn) unsubBtn.addEventListener('click', async () => { await handlePushUnsubscribe(wrapper, registration); });
        const toggleBtn = wrapper.querySelector('.fmr-push-help-toggle');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => {
                if (toggleBtn.dataset.fmrShown === '1') {
                    const helpEl = wrapper.querySelector('.fmr-push-help');
                    if (helpEl) helpEl.remove();
                    toggleBtn.textContent = 'Show troubleshooting tips';
                    toggleBtn.dataset.fmrShown = '';
                } else {
                    renderNotificationHelp(wrapper);
                }
            });
        }
    };

    const handlePushUnsubscribe = async (wrapper, registration) => {
        const status = wrapper.querySelector('.status-message');
        const hidden = wrapper.querySelector('input[type="text"]');
        const btn = wrapper.querySelector('button.push-notification-permission');
        const unsubBtn = wrapper.querySelector('.fmr-push-unsubscribe');
        try {
            if (unsubBtn) unsubBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing…';
            const sub = await registration.pushManager.getSubscription();
            if (sub) await sub.unsubscribe();
            try {
                await fetch((runUrl || '').replace(/\/?$/, '/') + 'ajax_delete_push_subscription', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                });
            } catch (e) { /* server delete is best-effort */ }
            try { localStorage.removeItem('push-notification-subscribed'); } catch (e) {}
            if (hidden) hidden.value = 'permission_denied';
            if (status) status.innerHTML = '<p>You have unsubscribed from notifications. Click the button to enable them again.</p>';
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-primary');
                btn.innerHTML = '<i class="fa fa-bell"></i> Enable Notifications';
            }
            wrapper.closest('.form-group')?.classList.remove('formr_answered');
        } catch (e) {
            console.error('unsubscribe failed', e);
            if (unsubBtn) unsubBtn.innerHTML = '<i class="fa fa-bell-slash"></i> Disable notifications';
        }
    };

    const markPushSubscribed = (wrapper, subscription, opts = {}) => {
        const hidden = wrapper.querySelector('input[type="text"]');
        const btn = wrapper.querySelector('button.push-notification-permission');
        if (hidden) {
            hidden.value = JSON.stringify(subscription);
            hidden.setCustomValidity('');
        }
        if (btn) {
            btn.disabled = true;
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-success');
            btn.innerHTML = '<i class="fa fa-check"></i> Notifications Enabled';
        }
        wrapper.closest('.form-group')?.classList.add('formr_answered');
        try { localStorage.setItem('push-notification-subscribed', 'true'); } catch (e) {}
    };

    const subscribeToPush = async (registration) => {
        if (!window.vapidPublicKey || typeof window.vapidPublicKey !== 'string' || window.vapidPublicKey.length < 10) {
            return { success: false, reason: 'invalid_config' };
        }
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') return { success: false, reason: 'permission_denied' };
            const sub = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(window.vapidPublicKey),
            });
            return { success: true, subscription: sub };
        } catch (e) {
            console.error('subscribe failed', e);
            return { success: false, reason: 'subscription_error', error: e };
        }
    };

    root.querySelectorAll('.push-notification-wrapper, .form-group.item-push_notification').forEach(async (wrapper) => {
        const hidden = wrapper.querySelector('input[type="text"]');
        const status = wrapper.querySelector('.status-message');
        const btn = wrapper.querySelector('button.push-notification-permission');
        const required = wrapper.closest('.form-group')?.classList.contains('required');
        if (required && hidden && hidden.value === 'not_requested') {
            hidden.setCustomValidity('Please complete this required step before continuing.');
        }
        if (!btn) return;

        // Step 1: iOS-but-not-standalone — push only works inside the
        // installed PWA. Tell the participant + disable the button + reword
        // the gating customValidity so the inline-error path explains the
        // install requirement (vs. the generic "complete this step").
        if (isIOSDevice() && !isStandaloneDisplayMode()) {
            if (hidden) {
                hidden.value = 'not_supported';
                if (required) {
                    hidden.setCustomValidity('Please add this study to your home screen first, then open it from the home screen icon to enable notifications.');
                }
            }
            if (status) status.innerHTML = '<div class="alert alert-warning" role="alert"><strong>Add to Home Screen first.</strong> On iOS, push notifications only work after you install this study to your home screen and open it from there.</div>';
            btn.disabled = true;
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-default');
            return;
        }

        // Step 2: capability checks. Each blocker also overrides the
        // gating customValidity so the inline-feedback path on Next click
        // names the actual blocker instead of the generic "complete this step".
        const setBlockedValidity = (msg) => {
            if (hidden && required) hidden.setCustomValidity(msg);
        };
        if (!('Notification' in window) || !('serviceWorker' in navigator)) {
            if (hidden) hidden.value = 'not_supported';
            if (status) status.innerHTML = '<div class="alert alert-warning" role="alert">Sorry, your browser does not support push notifications.</div>';
            setBlockedValidity('This browser does not support push notifications. Please use a supported browser to continue.');
            btn.disabled = true;
            return;
        }
        if (!window.vapidPublicKey) {
            if (hidden) hidden.value = 'not_supported';
            if (status) status.innerHTML = '<div class="alert alert-warning" role="alert">Push notifications are not configured for this study.</div>';
            setBlockedValidity('Push notifications are not configured for this study. Contact the study administrator.');
            btn.disabled = true;
            return;
        }
        if (!isIOSCompatibleVersion()) {
            if (hidden) hidden.value = 'not_supported';
            if (status) status.innerHTML = '<div class="alert alert-warning" role="alert">Sorry, push notifications require iOS 16.4 or later.</div>';
            setBlockedValidity('Push notifications require iOS 16.4 or later. Please update your device to continue.');
            btn.disabled = true;
            return;
        }

        // Step 3: SW must be registered (the form bundle does this).
        let registration;
        try { registration = await navigator.serviceWorker.ready; } catch (e) {}
        if (!registration || !registration.pushManager) {
            if (hidden) hidden.value = 'not_supported';
            if (status) status.innerHTML = '<div class="alert alert-warning" role="alert">Service worker not registered. Please reload the page and try again.</div>';
            setBlockedValidity('Service worker is not registered. Reload the page and try again.');
            btn.disabled = true;
            return;
        }

        // Step 4: rehydrate previous subscription state.
        let alreadySubscribed = false;
        try {
            const existing = await registration.pushManager.getSubscription();
            if (existing) {
                markPushSubscribed(wrapper, existing);
                renderNotificationControls(wrapper, registration, {
                    customMessage: 'Push notifications are enabled.',
                    showUnsubscribeButton: true,
                });
                alreadySubscribed = true;
            } else if (localStorage.getItem('push-notification-subscribed') === 'true' && Notification.permission === 'granted') {
                // iOS occasionally drops subscriptions silently. If permission
                // is still granted and we previously had a subscription, try
                // to re-subscribe transparently.
                const r = await subscribeToPush(registration);
                if (r.success) {
                    markPushSubscribed(wrapper, r.subscription);
                    await savePushSubscriptionToServer(r.subscription);
                    renderNotificationControls(wrapper, registration, {
                        customMessage: 'Push notifications are enabled.',
                        showUnsubscribeButton: true,
                    });
                    alreadySubscribed = true;
                }
            }
        } catch (e) {}
        if (alreadySubscribed) return;

        // Step 5: react to current permission state.
        const perm = Notification.permission;
        if (perm === 'denied') {
            if (hidden) hidden.value = 'permission_denied';
            setBlockedValidity('Notifications are blocked. Enable them in your browser/system settings, then tap the button again.');
            renderNotificationControls(wrapper, registration, {
                showTestButton: false,
                customMessage: 'You have declined push notifications. You can enable them in your browser settings, then click the button below.',
            });
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-default');
            btn.innerHTML = '<i class="fa fa-times"></i> Notifications blocked — click after enabling in browser settings';
        } else {
            // Default permission state — clear instruction.
            if (status) status.innerHTML = '<p>Click the button to enable push notifications.</p>';
            btn.innerHTML = '<i class="fa fa-bell"></i> Enable Notifications';
        }

        // Step 6: click handler.
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            if (btn.disabled && !btn.classList.contains('btn-default')) return; // disabled-success state
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing…';
            try {
                // Re-check existing subscription (participant may have
                // accepted via another tab).
                const existing = await registration.pushManager.getSubscription();
                if (existing) {
                    markPushSubscribed(wrapper, existing);
                    await savePushSubscriptionToServer(existing);
                    renderNotificationControls(wrapper, registration, {
                        customMessage: 'Push notifications are already enabled.',
                        showUnsubscribeButton: true,
                    });
                    return;
                }
                const result = await subscribeToPush(registration);
                if (result.success) {
                    markPushSubscribed(wrapper, result.subscription);
                    const saved = await savePushSubscriptionToServer(result.subscription);
                    document.dispatchEvent(new CustomEvent('pushSubscriptionChanged', {
                        detail: { action: 'subscribed', subscription: result.subscription },
                    }));
                    const androidNote = /android/i.test(navigator.userAgent)
                        ? '<p><strong>Note for Android users:</strong> on some devices, you may need to restart your browser or add this study to your home screen for notifications to work properly.</p>'
                        : '';
                    renderNotificationControls(wrapper, registration, {
                        customMessage: saved
                            ? 'Push notifications enabled successfully!'
                            : 'Push notifications enabled locally — couldn\'t save the subscription on the server. Reload to retry.',
                        additionalContent: '<p>A test notification was sent. If you didn\'t see it, your system settings might be blocking it.</p>' + androidNote,
                        showUnsubscribeButton: true,
                    });
                    await sendTestNotification(registration);
                } else if (result.reason === 'permission_denied') {
                    if (hidden) hidden.value = 'permission_denied';
                    setBlockedValidity('You declined push notifications. Enable them in your browser/system settings, then reload to try again.');
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-default');
                    btn.innerHTML = '<i class="fa fa-times"></i> Notifications blocked';
                    btn.disabled = true;
                    renderNotificationControls(wrapper, registration, {
                        showTestButton: false,
                        customMessage: 'You declined push notifications. You can enable them in your browser settings later.',
                    });
                } else if (result.reason === 'invalid_config') {
                    if (hidden) hidden.value = 'not_supported';
                    setBlockedValidity('Push notifications are not configured for this study. Contact the study administrator.');
                    if (status) status.innerHTML = '<div class="alert alert-warning" role="alert">Server configuration error. Please contact the study administrator.</div>';
                    btn.innerHTML = '<i class="fa fa-exclamation-triangle"></i> Configuration error';
                    btn.disabled = true;
                } else {
                    if (hidden) hidden.value = 'not_supported';
                    // Subscription failed — most often this is iOS Safari
                    // refusing to subscribe outside a home-screen-installed
                    // PWA. Surface the install hint either way; on Android /
                    // desktop it's still applicable advice.
                    setBlockedValidity('We couldn\'t enable push notifications. If you\'re on iOS, make sure you\'ve added this study to your home screen and opened it from there.');
                    const iosHint = isIOSDevice()
                        ? ' On iOS, push notifications only work after you\'ve added this study to your home screen and opened it from there.'
                        : '';
                    if (status) status.innerHTML = '<div class="alert alert-warning" role="alert"><strong>Couldn\'t enable push notifications.</strong>' + iosHint + ' Please try again later or contact the study administrator.</div>';
                    btn.innerHTML = '<i class="fa fa-exclamation-triangle"></i> Error — try again';
                }
            } catch (err) {
                console.error('push subscription click failed', err);
                btn.innerHTML = original;
                setBlockedValidity('We hit an unexpected error enabling push notifications. Please reload and try again.');
                if (status) status.innerHTML = '<div class="alert alert-warning" role="alert"><strong>Couldn\'t enable push notifications.</strong> Please reload and try again, or contact the study administrator if this keeps happening.</div>';
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

    initGeopoint(root);

    // --- Reactive showif via Alpine (Phase 3) ---
    // The Alpine `fmrForm` component + `x-showif` directive are registered
    // once at module load (showif/alpine.js). This call promotes the
    // server-emitted `data-showif="<transpiled-js>"` attribute to `x-showif`
    // and adds `x-data="fmrForm"` on the root form.
    promoteShowifAttributes(root);

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

    // initButtonGroups + initMediaRecorders + tom-select wiring (plain
    // <select> and <input.select2add>) live in items/* modules — imports above.
    initButtonGroups(root);
    initMediaRecorders(root);
    window.fmrInitMediaRecorders = () => initMediaRecorders(root);
    initTomSelects(root);


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

    // RequestCookie + RequestPhone + admin "monkey bar" preview live in items/* modules.
    initRequestCookie(root);
    initRequestPhone(root);
    initAdminPreview({ root, getCurrentPage: () => pages[currentIndex] });

    // Test-side signal that initForm finished — every listener is attached,
    // every item's init has run. Without this, real-device tests race the
    // bundle: the submit handler attaches LATE in initForm so a click on
    // [data-fmr-next] before that line does the default form-POST instead
    // of the JSON path. waitForBundle() in tests/e2e/helpers/v2Form.js
    // polls for window.fmrFormReady.
    window.fmrFormReady = true;
}

// Alpine `fmrForm` component + `x-showif` directive registered at module
// load (so they're in place before Alpine.start() scans the DOM). One Alpine
// scope on the outer <form>, one custom directive; everything else rides
// built-ins.
registerFmrForm(Alpine);
registerXShowif(Alpine);

window.Alpine = Alpine;

document.addEventListener('DOMContentLoaded', () => {
    initForm();
    Alpine.start();
});
