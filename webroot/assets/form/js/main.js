// form_v2 participant bundle.
// Single-page AJAX form: all pages rendered server-side in one document,
// client-side navigation between `<section data-fmr-page>` wrappers, page-at-
// a-time AJAX submission. Alpine drives reactive `showif` (`x-showif`
// directive + `fmrForm` component); everything else (offline queue,
// button-group wiring, file uploads, geopoint) is plain DOM/fetch.

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
// Initialize vanilla-cookieconsent exactly as v1's participant bundle does
// (site/js/main.js): its module body calls run({...}) to configure + start the
// consent singleton (cookie `formrcookieconsent`, necessary + functionality
// categories). Without this the request_cookie item's showPreferences() call has
// no singleton to open. Shows the consent bar once until consent is given (parity
// with v1); no-op afterwards.
import '../../common/js/cookieconsent.js';

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
import {
    queueAdd, queueGetAll, queueDelete, queueUpdate, queueSupersede, queueResetRetry,
    wipeQueueForRun, buildFormData, normalizeSyncEntry, isTransientFailure,
    entryState, entryIsDue, markRetryFailure, markNeedsAttention,
    STATE_PENDING, STATE_NEEDS_ATTENTION, STATE_BLOCKED,
} from './offline/queue.js';
import { initMediaRecorders } from './items/recorders.js';
import { initButtonGroups } from './items/button-groups.js';
import { initRadioDeselect } from './items/radio-deselect.js';
import { initBotCheck } from './items/bot-check.js';
import { initRank } from './items/rank.js';
import { initGeopoint } from './items/geopoint.js';
import { initTomSelects } from './items/tom-select.js';
import { initChooseWeekdays } from './items/choose-weekdays.js';
import { initRequestCookie } from './items/request-cookie.js';
import { initRequestPhone } from './items/request-phone.js';
import { initAdminPreview } from './items/admin-preview.js';
import { initExpiryNotifier } from './items/expiry-notifier.js';
import { initAutoSubmit } from './items/auto-submit.js';
import { t } from './lib/i18n.js';
import {
    clearCustomValidity,
    findErrorTarget,
    applyErrors,
    validatePageAndShowFeedback,
    installFeedbackClearer,
    installBlurValidation,
} from './validation/feedback.js';
import {
    registerFmrForm,
    registerXShowif,
    promoteShowifAttributes,
} from './showif/alpine.js';
import { initSolo } from './solo/controller.js';
import { createStore, pageState, readRememberedPage, rememberPage, forgetPage } from './state.mjs';
import { collectAnswers, fieldKey } from './lib/inputs.js';

function initForm() {
    const root = document.querySelector('.fmr-form-v2');
    if (!root) return;

    const pages = Array.from(root.querySelectorAll('[data-fmr-page]'));
    if (pages.length === 0) return;

    let currentIndex = 0;
    // Solo step controller (layout=solo). Assigned after submitPage is defined;
    // showPage() calls its onPageShown hook so a page swap re-seats stepping.
    let soloController = null;
    const isSolo = root.dataset.layout === 'solo';

    const progressBar = root.querySelector('[data-fmr-progress-bar]');
    const progressLabel = root.querySelector('[data-fmr-progress-label]');

    // ---- Save indicator ---------------------------------------------------
    // Surfaces the existing /form-page-submit + /form-sync state to the
    // participant so they know the answers are safely persisted. Not a new
    // data flow; just a visible affordance. aria-live="polite" gives screen
    // readers an unobtrusive announcement.
    const savePill = document.createElement('div');
    savePill.className = 'fmr-save-pill';
    savePill.setAttribute('role', 'status');
    savePill.setAttribute('aria-live', 'polite');
    savePill.innerHTML = '<span class="fmr-save-pill-icon" aria-hidden="true"></span><span class="fmr-save-pill-text"></span>';
    root.appendChild(savePill);
    const savePillText = savePill.querySelector('.fmr-save-pill-text');
    let savePillHideTimer = null;
    const setSaveState = (state, text, autohideMs = 1800) => {
        if (savePillHideTimer) { clearTimeout(savePillHideTimer); savePillHideTimer = null; }
        savePill.dataset.state = state;
        savePillText.textContent = text;
        savePill.classList.add('is-visible');
        if (autohideMs > 0) {
            savePillHideTimer = setTimeout(() => savePill.classList.remove('is-visible'), autohideMs);
        }
    };

    // ---- Completion overlay --------------------------------------------
    // Brief check-mark beat between the last successful POST and the
    // run-level redirect — the abrupt jump from a filled form to the
    // authored end-page felt curt.
    const completion = document.createElement('div');
    completion.className = 'fmr-completion-overlay';
    completion.setAttribute('role', 'status');
    completion.setAttribute('aria-live', 'polite');
    completion.hidden = true;
    completion.innerHTML = '<div class="fmr-completion-card"><div class="fmr-completion-icon" aria-hidden="true"></div><div class="fmr-completion-text">Thanks — sending your answers…</div></div>';
    root.appendChild(completion);
    const showCompletion = (text) => {
        const t = completion.querySelector('.fmr-completion-text');
        if (text && t) t.textContent = text;
        completion.hidden = false;
        // Force layout flush so the opacity transition runs.
        // eslint-disable-next-line no-unused-expressions
        completion.offsetHeight;
        completion.classList.add('is-visible');
    };

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
        const changed = (i !== currentIndex);
        pages.forEach((p, idx) => { p.hidden = (idx !== i); });
        currentIndex = i;
        // Let per-item widgets react to leaving a page (e.g. recorders stop
        // a live recording so the mic isn't left hot on an unseen page).
        if (changed) {
            document.dispatchEvent(new CustomEvent('fmr:pagechange', { detail: { index: i } }));
        }
        const p = pages[i];
        const pageNum = p ? Number(p.dataset.fmrPage) : null;
        if (pageNum) rememberPage(unitSessionId, pageNum);
        // In solo, the step controller owns progress (per-item, not per-page)
        // and focus; it re-seats stepping via onPageShown below.
        if (!isSolo && pageNum) {
            const { index, total } = nav().progress(pageNum);
            if (progressBar) {
                // Interpolate within the study's [from, to] window
                // (add_percentage_points / displayed_percentage_maximum) so
                // runs stitching several surveys into one 0–100 bar keep
                // their coordinated ranges — parity with v1's stretch math.
                const from = Number(root.dataset.fmrProgressFrom) || 0;
                const to = Number(root.dataset.fmrProgressTo) || 100;
                const pct = Math.round(from + (index / Math.max(1, total)) * (to - from));
                progressBar.style.width = pct + '%';
                progressBar.setAttribute('aria-valuenow', String(pct));
            }
            if (progressLabel) {
                progressLabel.textContent = t('progress.page_of', 'Page %cur of %total', { cur: index, total });
            }
        }
        if (p) {
            applyNav(p);
            observeItems(p);
            if (isSolo) {
                if (soloController) soloController.onPageShown(p);
                return;
            }
            window.scrollTo({ top: 0, behavior: 'smooth' });
            // a11y: move focus into the new page so keyboard + screen-
            // reader users continue from a sensible anchor rather than
            // hanging on the now-hidden previous page's button.
            const focusTarget = p.querySelector('.fmr-page-title, h1, h2, h3, h4')
                || p.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])');
            if (focusTarget) {
                // Headings aren't focusable by default — give a temporary
                // tabindex so they can receive focus, then remove it on blur.
                if (!/^(INPUT|SELECT|TEXTAREA|BUTTON|A)$/.test(focusTarget.tagName) && !focusTarget.hasAttribute('tabindex')) {
                    focusTarget.setAttribute('tabindex', '-1');
                    focusTarget.addEventListener('blur', () => focusTarget.removeAttribute('tabindex'), { once: true });
                }
                try { focusTarget.focus({ preventScroll: true }); } catch (e) { /* noop */ }
            }
        }
    };

    // Every page carries the same navigation row (FormRenderer::renderPageNav):
    // Previous is shown only when there is a shown page before this one, and
    // the advance button reads Submit on the last shown page, Next before it
    // — an authored submit label (no data-fmr-nav-label) is left alone.
    const applyNav = (pageEl) => {
        const pageNum = Number(pageEl.dataset.fmrPage);
        const ps = nav();
        pageEl.querySelectorAll('[data-fmr-prev]').forEach((btn) => { btn.hidden = ps.previousOf(pageNum) == null; });
        const last = ps.isLastShown(pageNum);
        pageEl.querySelectorAll('[data-fmr-nav-label]').forEach((btn) => {
            const label = t(last ? 'nav.submit' : 'nav.next', last ? 'Submit' : 'Next');
            if (btn.tagName === 'INPUT') { btn.value = label; return; }
            const icon = btn.querySelector('i');
            btn.textContent = label + ' ';
            if (icon) { icon.className = 'fa ' + (last ? 'fa-check' : 'fa-arrow-right'); btn.appendChild(icon); }
        });
    };
    // A showif toggle can change which pages are shown (and so which is
    // last); refresh the page's chrome.
    root.addEventListener('fmr:showif-toggled', () => { if (pages[currentIndex]) applyNav(pages[currentIndex]); });

    // Answered-timing: stamp on first value change per item. Suppressed while
    // resolveAndSubstitutePage writes server-computed deferred-fill values —
    // those dispatch input/change for Alpine/showif sync but are not
    // participant answers and must not fake an answered timestamp.
    let programmaticFill = false;
    root.querySelectorAll('input[name], select[name], textarea[name]').forEach((inp) => {
        if (inp.name && inp.name.startsWith('_item_views')) return;
        inp.addEventListener('change', () => {
            if (programmaticFill) return;
            const wrapper = inp.closest('.item');
            if (!wrapper) return;
            const ans = wrapper.querySelector('.item_answered');
            if (ans && !ans.value) ans.value = mysqlDatetime();
            const ansR = wrapper.querySelector('.item_answered_relative');
            if (ansR && !ansR.value) ansR.value = String(Math.round(performance.now()));
        });
    });

    // Solo layout (layout=solo): the Typeform-style one-item-per-screen step
    // controller is initialized further down (after submitPage is defined, so
    // it can drive page submission at page boundaries). See ./solo/controller.js.

    const collectPayload = (pageEl) => {
        // Answers: lib/inputs.js holds the rules (arrays, choice groups, the
        // Check_Item partner, disabled = withdrawn); this only adds what a
        // page submit needs on top.
        const data = collectAnswers(pageEl, root);
        const itemViews = { shown: {}, shown_relative: {}, answered: {}, answered_relative: {} };
        pageEl.querySelectorAll('input[name^="_item_views"]').forEach((inp) => {
            const m = inp.name.match(/^_item_views\[(\w+)\]\[(\d+)\]$/);
            if (!m) return;
            itemViews[m[1]] = itemViews[m[1]] || {};
            itemViews[m[1]][m[2]] = inp.value;
        });
        // A file WITH a selection rides the multipart/FormData path (bytes
        // can't go in JSON). An EMPTY OPTIONAL file/image/audio/video input
        // still posts '' so the server stores it as shown-and-left-blank (v1
        // got this for free from the browser's empty $_FILES entry);
        // required-but-empty file items are blocked client-side before this.
        pageEl.querySelectorAll('input[type=file][name]:not([disabled])').forEach((inp) => {
            if (inp.files && inp.files.length > 0) return;
            const group = inp.closest('.form-group');
            if (group && group.classList.contains('optional')) data[fieldKey(inp.name).key] = '';
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
    // missing (the column defaults, patch 086).
    const offlineModeEnabled = (root.dataset.offlineMode || 'on') !== 'off';
    const allowPrevious = (root.dataset.allowPrevious || 'off') !== 'off';
    // When offline mode is off, don't expose the sync URL to the queue path —
    // submissions fail hard as if the endpoint didn't exist.
    const syncUrl = offlineModeEnabled ? root.dataset.syncUrl : '';
    // Incremental autosave endpoint (form-save). Persists answers as they're
    // given (rate-limited) so a mid-page breakoff still yields partial data,
    // independent of the explicit page-submit. Best-effort; see persist block.
    const saveUrl = root.dataset.saveUrl || '';
    // The server's state block (FormRenderer::stateBlock): the unit session
    // this page was rendered for — it rides along on every write so the
    // server can reject a stale tab's writes into a newer unit session
    // (repeated Forms / diary runs) — and, per item, what is stored. The
    // store is the browser's copy; every submit reply is merged into it and
    // navigation is derived from it (pageState, ./state.mjs).
    let stateBlock = {};
    try { stateBlock = JSON.parse((root.querySelector('script.fmr-state') || {}).textContent || '{}'); } catch (e) { /* malformed: empty */ }
    const store = createStore(stateBlock);
    const unitSessionId = store.unitSessionId;

    // A snapshot of the document for pageState: per page, its items with the
    // live showif result (`.hidden` is what Alpine toggles, showif/alpine.js
    // applyVisibility) and whether they take input (the server's word, from
    // the state block: a rank item's inputs are all type=hidden, a note's
    // is too). A submit item is the page's button, not content.
    const itemNameOf = (wrapper) => {
        const inp = wrapper.querySelector('input[name]:not([name^="_item_views"]), select[name], textarea[name]');
        if (!inp) return null;
        return inp.name.endsWith('[]') ? inp.name.slice(0, -2) : inp.name;
    };
    const snapshotPages = () => pages.map((pageEl) => ({
        page: Number(pageEl.dataset.fmrPage),
        items: Array.from(pageEl.querySelectorAll(':scope > .form-group, :scope > .item'))
            .filter((el) => !el.classList.contains('item-submit'))
            .map((el) => {
                const name = itemNameOf(el);
                return {
                    name,
                    visible: !el.classList.contains('hidden'),
                    answerable: !!(name && store.items[name] && store.items[name].answerable),
                };
            }),
    }));
    const nav = () => pageState(snapshotPages(), store, readRememberedPage(unitSessionId), allowPrevious);
    const pageIndexOf = (pageNum) => pages.findIndex((p) => Number(p.dataset.fmrPage) === Number(pageNum));
    // The ids of every item the form currently hides by showif, all pages:
    // posted with each submit so the server's record of hidden items — and
    // its final required check — agree with what the participant saw.
    const hiddenItemIds = () => Array.from(root.querySelectorAll('[data-fmr-hidden]')).map((el) => {
        const v = el.querySelector('.item_shown');
        const m = v && v.name && v.name.match(/\[(\d+)\]/);
        return m ? Number(m[1]) : null;
    }).filter((id) => id != null);

    // --- Offline queue (Phase 5) ---
    // IDB plumbing + multipart serialization + isTransientFailure live in
    // ./offline/queue.js (imports above). genUuid in ./lib/uuid.js. The
    // banner + drain orchestration stays here because it pokes `root` and
    // window navigation directly.
    let queueBanner = null;
    // `actions` — optional [{label, onClick}] rendered as buttons after the
    // message (Retry / Go to page). Message is textContent, never HTML: the
    // banner may carry server-supplied error text.
    const showQueueBanner = (msg, variant, actions) => {
        if (!queueBanner) {
            queueBanner = document.createElement('div');
            queueBanner.className = 'alert fmr-queue-banner';
            queueBanner.setAttribute('role', 'status');
            root.insertBefore(queueBanner, root.firstChild);
        }
        queueBanner.classList.remove('alert-warning', 'alert-success', 'alert-danger');
        queueBanner.classList.add(variant === 'success' ? 'alert-success'
            : variant === 'danger' ? 'alert-danger' : 'alert-warning');
        queueBanner.dataset.queueState = variant || 'warning';
        const text = document.createElement('span');
        text.className = 'fmr-queue-banner-text';
        text.textContent = msg;
        queueBanner.replaceChildren(text);
        (actions || []).forEach((a) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-sm btn-outline-dark ms-2 fmr-queue-banner-action';
            btn.textContent = a.label;
            btn.addEventListener('click', (e) => { e.preventDefault(); a.onClick(btn); });
            queueBanner.appendChild(btn);
        });
        queueBanner.hidden = false;
    };
    const hideQueueBanner = () => { if (queueBanner) queueBanner.hidden = true; };

    // Single-flight: the drain is triggered by `online`, by initial load, by
    // the retry timer and by page submits — two interleaved drains would
    // double-fire the banner and the final-page redirect (server UUID dedup
    // makes the POSTs themselves harmless).
    let drainInFlight = false;
    const drainQueue = async () => {
        if (!syncUrl || drainInFlight) return;
        drainInFlight = true;
        try {
            await drainQueueInner();
        } finally {
            drainInFlight = false;
        }
    };
    // Timer for the next automatic retry of a backed-off entry (see
    // renderQueueSummary). One at a time; re-armed after every drain.
    let queueRetryTimer = null;
    const scheduleQueueRetry = (delayMs) => {
        if (queueRetryTimer) clearTimeout(queueRetryTimer);
        queueRetryTimer = setTimeout(() => { queueRetryTimer = null; drainQueue(); }, Math.max(1000, Math.min(delayMs, 60 * 60 * 1000)));
    };

    // Post one entry to its run's /form-sync. Throws on network failure
    // (caller treats that as "still offline"); resolves {res, body}.
    const postQueueEntry = async (entry, entrySyncUrl) => {
        const hasFiles = entry.files && Object.keys(entry.files).length > 0;
        let res;
        if (hasFiles) {
            // Multipart drain — the same request builder as a live submit.
            res = await fetch(entrySyncUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                body: buildFormData(normalizeSyncEntry(entry)),
            });
        } else {
            res = await fetch(entrySyncUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                // normalizeSyncEntry, not the raw entry: /form-sync 400s a
                // client_ts that isn't a MySQL DATETIME (it used to 500 at
                // INSERT time, after the answers were already applied — the
                // client then retried and double-wrote). A 400 blocks the
                // entry, so one stored by an older bundle is fixed up here.
                body: JSON.stringify(normalizeSyncEntry(entry)),
            });
        }
        const body = await res.json().catch(() => null);
        return { res, body };
    };

    // Classify a /form-sync response into a drain outcome. Every shape
    // RunController::formSyncAction can answer with is named here:
    //   applied   200 {status:'ok', items, redirect?} / {already_applied}
    //   errors    200 {status:'errors', errors:{name: msg}} — the server rolled
    //             the ledger row back and applied NOTHING; the entry must be
    //             kept and the participant has to fix the page.
    //   drop      409 {drop_entry:true} — session ended / different unit.
    //   throttled 429 — retry the run later, don't count an attempt.
    //   session   403 "No active run session", or a 200 that isn't JSON (an
    //             auth redirect to a login page) — session gone for this run.
    //   invalid   400 envelope error — permanent for this entry.
    //   server    anything else (5xx, unexpected 4xx).
    const classifySyncResponse = (res, body) => {
        if (body && body.already_applied) return 'applied';
        if (res.ok && body && body.status === 'errors') {
            const errs = body.errors && typeof body.errors === 'object' ? body.errors : {};
            // An empty error map means updateSurveyStudyRecord bailed for a
            // non-validation reason (ended session, …): nothing for the
            // participant to fix, so treat it as a retryable failure.
            return Object.keys(errs).length ? 'errors' : 'server';
        }
        if (res.ok && body && body.status === 'ok') return 'applied';
        if (res.ok && !body) return 'session';
        if (body && body.drop_entry) return 'drop';
        if (res.status === 429) return 'throttled';
        if (res.status === 403) return 'session';
        if (res.status === 400) return 'invalid';
        return 'server';
    };

    const drainQueueInner = async () => {
        let entries;
        try { entries = await queueGetAll(); } catch (e) { return; }
        if (!entries || !entries.length) { hideQueueBanner(); return; }
        // Oldest first. client_ts has one-second resolution, so pages queued
        // within the same second fall back to `queued_at` (ms) — IDB returns
        // entries in uuid order, i.e. at random, and a later page must never
        // apply first (its calculations would run without the earlier page).
        entries.sort((a, b) => (a.client_ts || '').localeCompare(b.client_ts || '')
            || ((a.queued_at || 0) - (b.queued_at || 0)));
        // Scope the drain per run: one run's rejection (expired session,
        // validation error) must not head-of-line block another run's entries
        // on this origin. Legacy entries without sync_url belong to this form.
        const groups = new Map();
        entries.forEach((entry) => {
            const key = entry.sync_url || syncUrl;
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(entry);
        });
        const dueCount = entries.filter((e) => entryIsDue(e)).length;
        if (dueCount) {
            showQueueBanner(t('queue.syncing', 'Syncing %n queued submission(s)…', { n: dueCount }), 'warning');
        }
        let ownRedirect = null;
        for (const [entrySyncUrl, groupEntries] of groups) {
            const isOwnRun = entrySyncUrl === syncUrl;
            const outcome = await drainRunGroup(groupEntries, entrySyncUrl, isOwnRun);
            if (outcome === 'offline') return;
            if (outcome && outcome.redirect) ownRedirect = outcome.redirect;
        }
        await renderQueueSummary(ownRedirect);
    };

    // Drain one run's entries oldest-first. Returns 'offline' (abort every
    // group), or {redirect} when this run's final page landed.
    const drainRunGroup = async (groupEntries, entrySyncUrl, isOwnRun) => {
        let redirect = null;
        for (const entry of groupEntries) {
            if (!entryIsDue(entry)) {
                // A rejected page halts ITS run's queue: later pages must not
                // land ahead of the one the participant still has to fix (the
                // final page's redirect would otherwise bounce them out of the
                // form). Backoff / blocked entries just skip.
                if (entryState(entry) === STATE_NEEDS_ATTENTION) break;
                continue;
            }
            let res, body;
            try {
                ({ res, body } = await postQueueEntry(entry, entrySyncUrl));
            } catch (e) {
                // Still offline / transient. Leave entries in place; next `online` retries.
                showQueueBanner(t('queue.offline_wait', 'Offline — submissions will sync automatically when you reconnect.'), 'warning');
                return 'offline';
            }
            const outcome = classifySyncResponse(res, body);
            if (outcome === 'applied') {
                await queueDelete(entry.uuid);
                // The stored state of what just landed (and any calculate
                // items a final entry ran) — for this form's showifs.
                if (isOwnRun) absorbReply(body);
                // If the drain applied the final page of the form, the server
                // returns a redirect so Run::exec can advance. Taken by
                // renderQueueSummary once nothing else is pending for THIS run
                // — another run's redirect would hijack the participant out of
                // the form they're on.
                if (body && body.redirect && isOwnRun) redirect = body.redirect;
                continue;
            }
            if (outcome === 'drop') {
                // Session ended / different unit — the server says this entry
                // can never apply. Stop retrying it.
                await queueDelete(entry.uuid);
                continue;
            }
            if (outcome === 'errors') {
                // Validation failure: the server applied nothing (ledger row
                // rolled back). KEEP the answers; the participant fixes the
                // page and re-submits it, which supersedes this entry.
                markNeedsAttention(entry, body.errors);
                await queueUpdate(entry).catch(() => {});
                break;
            }
            if (outcome === 'throttled') break; // 429: retry the run later, no attempt counted
            const status = res.status;
            const message = (body && body.error) ? String(body.error) : '';
            if (outcome === 'invalid') {
                // 400 envelope error — re-POSTing the same bytes can't succeed.
                markRetryFailure(entry, { reason: 'invalid', status, message, permanent: true });
                await queueUpdate(entry).catch(() => {});
                continue;
            }
            if (outcome === 'session') {
                // No run session for this run: every entry of the run fails
                // the same way, so back them all off together instead of
                // burning one attempt per entry per drain.
                for (const e of groupEntries) {
                    if (!entryIsDue(e)) continue;
                    markRetryFailure(e, { reason: 'session', status, message });
                    await queueUpdate(e).catch(() => {});
                }
                break;
            }
            // 5xx / unexpected: back this entry off and leave the rest of the
            // run for the next drain (a sick server won't take them either).
            markRetryFailure(entry, { reason: 'server', status, message });
            await queueUpdate(entry).catch(() => {});
            break;
        }
        return redirect ? { redirect } : null;
    };

    // After a drain: take this run's redirect if its queue is clear, else
    // explain what's still on disk and why. Priority: a page the participant
    // must fix > entries we gave up on (Retry button) > entries in backoff
    // (auto-retry timer + Retry button) > everything sent.
    const renderQueueSummary = async (ownRedirect) => {
        let remaining;
        try { remaining = await queueGetAll(); } catch (e) { return; }
        const belongsHere = (e) => (e.sync_url || syncUrl) === syncUrl;
        const ownRemaining = remaining.filter(belongsHere);
        if (ownRedirect && !ownRemaining.length) {
            window.location.href = ownRedirect;
            return;
        }
        if (!remaining.length) {
            setSaveState('synced', t('save.synced', 'Synced'), 2500);
            showQueueBanner(t('queue.all_sent', 'All queued submissions have been sent.'), 'success');
            setTimeout(hideQueueBanner, 3000);
            return;
        }
        const attention = remaining.filter((e) => entryState(e) === STATE_NEEDS_ATTENTION);
        if (attention.length) {
            const own = attention.find(belongsHere);
            if (own) surfaceNeedsAttention(own);
            else showQueueBanner(t('queue.needs_attention_other_run', 'A queued submission for another study on this device was rejected. Open that study to fix it.'), 'danger');
            return;
        }
        const retryAction = { label: t('queue.retry', 'Retry now'), onClick: async (btn) => {
            btn.disabled = true;
            btn.textContent = t('queue.retrying', 'Retrying…');
            try { await queueResetRetry(); } catch (e) { /* drain re-renders */ }
            drainQueue();
        } };
        const blocked = remaining.filter((e) => entryState(e) === STATE_BLOCKED);
        if (blocked.length) {
            const session = blocked.some((e) => e.last_error && e.last_error.reason === 'session');
            showQueueBanner(session
                ? t('queue.blocked_session', '%n queued submission(s) could not be sent because your session has expired. Open your personal study link to sign in again, then retry.', { n: blocked.length })
                : t('queue.blocked', '%n queued submission(s) could not be sent after several attempts. Your answers are kept on this device — please retry, or contact the study team if this persists.', { n: blocked.length }),
            'danger', [retryAction]);
            return;
        }
        const pending = remaining.filter((e) => entryState(e) === STATE_PENDING);
        if (pending.length) {
            showQueueBanner(t('queue.retry_later', "%n queued submission(s) could not be sent yet. We'll retry automatically.", { n: pending.length }), 'warning', [retryAction]);
            const now = Date.now();
            const waits = pending.map((e) => (e.next_retry_at ? e.next_retry_at - now : 60 * 1000));
            scheduleQueueRetry(Math.max(0, Math.min.apply(null, waits)));
        }
    };

    // A queued page the server rejected with per-item errors: render them
    // through the same feedback path the live submit uses (applyErrors) when
    // that page is on screen, and give the participant a way back to it
    // otherwise. The entry stays in IDB until the page is re-submitted.
    const surfaceNeedsAttention = (entry) => {
        const pageNum = Number(entry.page);
        const idx = pages.findIndex((p) => Number(p.dataset.fmrPage) === pageNum);
        const errors = entry.errors || {};
        const goToPage = () => {
            if (idx === -1) return;
            showPage(idx);
            try { history.pushState(null, '', `?page=${pageNum}`); } catch (e) { /* noop */ }
            clearCustomValidity(pages[idx]);
            applyErrors(pages[idx], errors);
        };
        const actions = [];
        if (idx !== -1 && idx === currentIndex) {
            clearCustomValidity(pages[idx]);
            applyErrors(pages[idx], errors);
        } else if (idx !== -1) {
            actions.push({ label: t('queue.go_to_page', 'Go to page %page', { page: pageNum }), onClick: goToPage });
        }
        showQueueBanner(t('queue.needs_attention', 'Your answers for page %page could not be saved: please fix the highlighted answers and submit the page again.', { page: pageNum }), 'danger', actions);
    };

    // --- Page-transition resolver (POST /form-render-page) ---
    // Resolves the upcoming page's dynamic labels + values via the batched
    // OpenCPU endpoint. Called between submit-success and showPage so the
    // participant sees the resolved content as soon as the new page appears.
    // No-op for pages without any dynamic items (no data-fmr-fill-id /
    // -label-id placeholders) — short-circuits with no network.
    const PAGE_RESOLVE_WAIT_MS = 800;
    // Write one value into its item — a resolved `value` when its page is
    // shown, or a stored answer at load — and return the input to fire
    // input/change on, or null when nothing was written. Choice items are
    // preset by ticking the matching choice: mc / select_one / check render a
    // hidden placeholder input ahead of their real controls, and writing the
    // value there would preset nothing visible while still posting it. A
    // multi-choice value arrives the way it is stored ("1, 3"). A `hidden`
    // item has only its hidden input, which is the target.
    const setInputValue = (wrapper, raw) => {
        const val = raw == null ? '' : String(raw);
        const named = Array.from(wrapper.querySelectorAll('input[name], textarea[name], select[name]'))
            .filter((el) => !el.name.startsWith('_item_views'));
        const choices = named.filter((el) => el.type === 'radio' || el.type === 'checkbox');
        if (choices.length) {
            if (val === '' || choices.some((el) => el.checked)) return null;
            const wanted = choices[0].name.endsWith('[]') ? val.split(/\s*,\s*/) : [val];
            const hits = choices.filter((el) => wanted.includes(el.value)
                || (el.type === 'checkbox' && !el.name.endsWith('[]') && /^(1|true)$/i.test(val)));
            hits.forEach((el) => {
                el.checked = true;
                if (el.id) {
                    wrapper.querySelectorAll(`.btn[data-for="${CSS.escape(el.id)}"]`)
                        .forEach((btn) => btn.classList.add('btn-checked'));
                }
            });
            return hits[0] || null;
        }
        const target = named.find((el) => el.type !== 'hidden') || named[0];
        if (!target) return null;
        if (target.tagName === 'SELECT') {
            const values = target.multiple ? val.split(/\s*,\s*/) : [val];
            if (val === '' || Array.from(target.selectedOptions).some((o) => o.value !== '')) return null;
            if (target.tomselect) {
                target.tomselect.setValue(target.multiple ? values : val, true);
            } else {
                Array.from(target.options).forEach((o) => { if (values.includes(o.value)) o.selected = true; });
            }
            return target;
        }
        if (target.value !== '' || val === '') return null; // already answered / nothing to write
        target.value = val;
        return target;
    };
    const resolveAndSubstitutePage = async (pageNum) => {
        const targetPage = pages.find((p) => Number(p.dataset.fmrPage) === Number(pageNum));
        if (!targetPage) return;
        const fillItems = targetPage.querySelectorAll('[data-fmr-fill-id]');
        const labelItems = targetPage.querySelectorAll('[data-fmr-label-id]');
        const choiceSpans = targetPage.querySelectorAll('[data-fmr-choice-id]');
        if (fillItems.length === 0 && labelItems.length === 0 && choiceSpans.length === 0) return;
        // Mark items pending while we wait — visual feedback.
        fillItems.forEach((el) => el.classList.add('fmr-fill-pending'));
        labelItems.forEach((el) => el.classList.add('fmr-fill-pending'));
        const settleChoices = () => choiceSpans.forEach((el) => el.classList.remove('fmr-fill-pending'));
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
                settleChoices();
                console.warn('form-render-page failed', res.status, body);
                return;
            }
        } catch (e) {
            // Network failure — leave placeholders in place and let the
            // participant continue. They'll see whatever's in the static
            // label / blank value field; not great but not fatal.
            fillItems.forEach((el) => el.classList.remove('fmr-fill-pending'));
            labelItems.forEach((el) => el.classList.remove('fmr-fill-pending'));
            settleChoices();
            console.warn('form-render-page network error', e);
            return;
        }
        const valuesMap = (body && body.values) || {};
        const labelsMap = (body && body.labels) || {};
        // Calculate items the page show just ran, for the showif scope.
        absorbReply({ computed: body.computed });
        // Substitute values (setInputValue). Don't clobber a value the
        // participant already gave (back-nav case).
        fillItems.forEach((wrapper) => {
            wrapper.classList.remove('fmr-fill-pending');
            const id = wrapper.dataset.fmrFillId;
            if (!(id in valuesMap)) return;
            const target = setInputValue(wrapper, valuesMap[id]);
            if (!target) return;
            programmaticFill = true;
            try {
                target.dispatchEvent(new Event('input', { bubbles: true }));
                target.dispatchEvent(new Event('change', { bubbles: true }));
            } finally {
                programmaticFill = false;
            }
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
        // Substitute R in choice labels. Radio/checkbox/button labels carry a
        // placeholder span; an <option> can't hold markup, so options (and a
        // tom-select built on them) are reached through the item + value.
        const choicesMap = (body && body.choices) || {};
        choiceSpans.forEach((span) => {
            const entry = choicesMap[span.dataset.fmrChoiceId];
            if (entry) span.innerHTML = entry.html;
        });
        settleChoices();
        Object.values(choicesMap).forEach((entry) => {
            const wrapper = targetPage.querySelector(`[data-fmr-choice-item="${Number(entry.item_id)}"]`);
            if (!wrapper) return;
            const tmp = document.createElement('div');
            tmp.innerHTML = entry.html;
            const text = tmp.textContent.trim();
            (entry.values || []).forEach((value) => {
                wrapper.querySelectorAll(`option[value="${CSS.escape(String(value))}"]`).forEach((opt) => {
                    opt.textContent = text;
                    const ts = opt.parentElement && opt.parentElement.tomselect;
                    if (ts) ts.updateOption(String(value), { value: String(value), text });
                });
            });
        });
    };

    // Read the current state of every named form input as a flat map. Used
    // for /form-render-page and /form-fill.
    // Values stay strings (empty → null), exactly as the inputs hold them:
    // the server types each one by its item's results column
    // (RCallService::formatROverlay). Converting numeric-looking strings here,
    // as the showif scope does, would hand R a text answer "07743" as 7743.
    const collectAllAnswers = () => {
        const out = {};
        const answers = collectAnswers(root, root);
        Object.keys(answers).forEach((key) => {
            const v = answers[key];
            out[key] = Array.isArray(v) ? v.filter((x) => x !== '') : (v === '' ? null : v);
        });
        return out;
    };

    // validatePageAndShowFeedback + the input-change listener that clears
    // stale `.fmr-invalid-feedback` live in ./validation/feedback.js.
    installFeedbackClearer(root);
    installBlurValidation(root);

    // Is the page's submit control enabled? items/auto-submit.js implements a
    // Submit_Item with a NEGATIVE data-timeout as "minimum exposure": the
    // button starts `disabled` and is enabled after |t| ms. A disabled button
    // can't be clicked, but the page can still be submitted implicitly (Enter
    // in a text input → the form's `submit` event, solo's onContinue, the
    // all-answered auto-submit), so every submit path consults this gate.
    // Selector mirrors solo/controller.js's submit-step lookup. Exposed as
    // `window.fmrCanSubmitPage` (and passed to initSolo as `canSubmitPage`).
    const SUBMIT_CONTROL_SELECTOR = '.item-submit button, .item-submit input[type=submit], button.submit_automatically_after_timeout, [data-fmr-next]';
    const canSubmitPage = (pageEl) => {
        const p = pageEl || pages[currentIndex];
        if (!p) return false;
        const controls = Array.from(p.querySelectorAll(SUBMIT_CONTROL_SELECTOR));
        return !controls.some((btn) => btn.disabled);
    };
    // Refuse + draw the eye to the still-disabled button (the fuse bar shows
    // how long is left). Returns true when the submit may proceed.
    const gateSubmit = (pageEl) => {
        if (canSubmitPage(pageEl)) return true;
        const p = pageEl || pages[currentIndex];
        const btn = p && Array.from(p.querySelectorAll(SUBMIT_CONTROL_SELECTOR)).find((b) => b.disabled);
        if (btn) {
            // Web Animations shake — no stylesheet dependency.
            try {
                if (typeof btn.animate === 'function') {
                    btn.animate([
                        { transform: 'translateX(0)' }, { transform: 'translateX(-5px)' },
                        { transform: 'translateX(5px)' }, { transform: 'translateX(-3px)' },
                        { transform: 'translateX(0)' },
                    ], { duration: 320, easing: 'ease-out' });
                }
                btn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            } catch (e) { /* noop */ }
        }
        return false;
    };

    const submitPageInner = async () => {
        const page = pages[currentIndex];
        if (!gateSubmit(page)) return;
        clearCustomValidity(page);
        if (!validatePageAndShowFeedback(page)) {
            return;
        }
        setSaveState('saving', t('save.saving', 'Saving…'), 0);
        const pageNum = Number(page.dataset.fmrPage) || (currentIndex + 1);
        const nextPage = nav().nextAfter(pageNum);
        // `hidden`: every item the form hides right now, all pages. `final`:
        // no shown page follows this one, so the server may end the form
        // once every required item is answered.
        const payload = Object.assign(
            { page: pageNum, unit_session_id: unitSessionId, hidden: hiddenItemIds(), final: nextPage == null },
            collectPayload(page)
        );

        // If this page has a file input with a selected file, switch to
        // FormData/multipart. File bytes can't ride in JSON, and the server
        // branches on Content-Type to read $_FILES for File_Item items.
        const fileInputs = Array.from(
            page.querySelectorAll('input[type="file"]:not([disabled])')
        ).filter((inp) => inp.name && inp.files && inp.files.length > 0);
        const useMultipart = fileInputs.length > 0;
        const files = {};
        fileInputs.forEach((inp) => {
            // File_Item reads from $_FILES['files'][itemName]; the placeholder
            // in `data` must not sit beside it.
            files[inp.name] = inp.files[0];
            delete payload.data[inp.name];
        });

        let res, netErr;
        try {
            if (useMultipart) {
                res = await fetch(submitUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    body: buildFormData(Object.assign({ files }, payload)),
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
                    ? t('error.too_large', "Submission too large to queue offline (%mb MB, limit %limit MB). Please retry when you're back online.", { mb: (oversizedFile.files[0].size / 1024 / 1024).toFixed(1), limit: QUEUE_FILE_SIZE_CAP_MB })
                    : t('error.send_failed', 'Your submission could not be sent. Please check your connection and try again.');
                window.alert(msg);
                return;
            }
            // unit_session_id binds the queue entry to the unit-session it
            // was submitted for; the server uses it to drop_entry if the
            // participant has since advanced to a different Form unit
            // (otherwise this page's answers would be silently written
            // into whatever Form unit is currently active — same FK shape,
            // wrong record). sync_url scopes the entry to THIS run: the
            // queue DB is origin-wide, so on path-routed deploys a drain
            // running on another run's page must post the entry to the run
            // it belongs to, not its own sync endpoint.
            const entry = {
                uuid: genUuid(),
                page: pageNum,
                unit_session_id: unitSessionId,
                sync_url: syncUrl,
                data: Object.assign({}, payload.data),
                item_views: payload.item_views,
                hidden: payload.hidden,
                final: payload.final,
                // MySQL DATETIME rejects ISO-8601 with ".sssZ" — same gotcha as
                // item_shown timestamps. Use the space-separated format.
                client_ts: mysqlDatetime(),
                // Drain order within one client_ts second (see drainQueueInner).
                queued_at: Date.now(),
            };
            // The File objects live beside `data`, never in it, so the
            // server's $_POST['data'] doesn't get a stringified File next
            // to the $_FILES entry.
            if (useMultipart) entry.files = files;
            try { await queueAdd(entry); } catch (e) {
                console.error('enqueue failed', e);
                window.alert(t('error.queue_failed', 'Your submission could not be queued locally. Please check your connection and try again.'));
                return;
            }
            // This submission replaces any earlier queued copy of the same page
            // (a rejected `needs_attention` one, or a plain re-visit): the old
            // bytes must not drain after — or instead of — the new ones.
            try { await queueSupersede(entry, entry.uuid); } catch (e) { /* best effort */ }
            // Ask the SW to wake us up on next connectivity change. No-op on
            // browsers without Background Sync — page-JS online event still
            // catches it there.
            registerBackgroundSync();
            setSaveState('offline', t('save.offline', 'Saved offline'), 2500);
            showQueueBanner(t('queue.queued_offline', "You're offline. This submission is queued and will be sent when you reconnect."), 'warning');
            // Advance locally so the participant can continue — the next
            // drain applies in order server-side. The store counts the posted
            // answers as given, so navigation moves on exactly as after a live
            // submit. If this was the last page, leave them on it; the run's
            // next unit is only known once the sync drains and redirects.
            store.markQueued(payload.data, payload.hidden);
            const queuedNext = nav().nextAfter(pageNum);
            if (queuedNext != null) goToPage(queuedNext);
            return;
        }
        if (!res.ok) {
            console.error('page-submit HTTP', res.status);
            // Drop the saving pill — error UI takes over.
            savePill.classList.remove('is-visible');
            const errBody = await res.json().catch(() => null);
            if (errBody && errBody.stale_session) {
                // This page was rendered for an earlier unit session (form
                // continued elsewhere / a new diary entry started). Reload so
                // the server re-renders the current state.
                window.alert(t('error.stale_session', 'This form was continued elsewhere or a new entry has started. The page will reload to catch up.'));
                window.location.reload();
                return;
            }
            if (res.status === 403) {
                // Run session gone — most likely the login session expired.
                window.alert(t('error.session_expired', 'Your session has expired. The page will reload so you can continue.'));
                window.location.reload();
                return;
            }
            window.alert(t('error.generic', 'Sorry, something went wrong. Please try again.'));
            return;
        }
        // A 200 that was redirected to a login/consent page (session expiry on
        // deployments where auth redirects instead of 403ing) parses as null
        // below — both cases mean "this tab's session is no longer valid".
        const body = await res.json().catch(() => null);
        if (!body) {
            savePill.classList.remove('is-visible');
            console.error('page-submit: unparseable response body', res.redirected ? `(redirected to ${res.url})` : '');
            if (res.redirected) {
                window.alert(t('error.session_expired', 'Your session has expired. The page will reload so you can continue.'));
                window.location.reload();
                return;
            }
            // 200 with an unparseable body (proxy mangling, dev-mode xdebug
            // noise) — tell the participant rather than leaving a dead Next
            // button with the pill stuck on "Saving…".
            window.alert(t('error.save_failed', 'Sorry, the answer could not be saved. Please reload the page and try again.'));
            return;
        }
        if (body.status === 'errors' && body.errors) {
            savePill.classList.remove('is-visible');
            applyErrors(page, body.errors);
            return;
        }
        // The server accepted this page: any earlier queued copy of it (e.g. a
        // rejected `needs_attention` entry the participant just fixed) is now
        // stale and must not drain over the accepted answers. Then re-run the
        // drain so the queue banner reflects what's left (hides when empty).
        if (syncUrl) {
            queueSupersede({ sync_url: syncUrl, unit_session_id: unitSessionId, page: pageNum })
                .then((removed) => { if (removed) drainQueue(); })
                .catch(() => {});
        }
        // The reply carries the stored state of what was just written (and
        // of calculate items the final submit ran): merge it into the store
        // and hand the values to the showif scope.
        absorbReply(body);
        if (body.redirect) {
            // Last page just landed — pause briefly so the participant sees
            // the completion beat instead of an abrupt jump to the end-page.
            forgetPage(unitSessionId);
            showCompletion(t('completion.next_step', 'Thanks — sending you to the next step…'));
            setTimeout(() => { window.location.href = body.redirect; }, 600);
            return;
        }
        setSaveState('saved', t('save.saved', 'Saved'));
        if (body.missing && Object.keys(body.missing).length) {
            // The final page is stored, but a required item elsewhere is still
            // unanswered (a showif revealed it after its page was submitted,
            // say): take the participant to the first such page.
            const first = firstPageOfItems(Object.keys(body.missing));
            if (first != null) {
                await goToPage(first);
                applyErrors(pages[currentIndex], body.missing);
                return;
            }
        }
        const next = nav().nextAfter(pageNum);
        if (next != null) {
            await goToPage(next);
            return;
        }
        // Nothing follows and the server did not end the form: the document
        // and the server disagree (the form's items changed since it was
        // rendered). Reload and let the server render the current state.
        window.location.href = runUrl || window.location.pathname;
    };

    // Merge a submit / sync reply into the store and the showif scope.
    const absorbReply = (body) => {
        if (!body || typeof body !== 'object') return;
        if (body.items) store.merge(body.items);
        const values = {};
        Object.keys(body.items || {}).forEach((name) => { values[name] = body.items[name].value; });
        Object.assign(values, body.computed || {});
        if (Object.keys(values).length && window.Alpine) {
            try { window.Alpine.$data(root)._seed(values); } catch (e) { /* noop */ }
        }
    };
    const firstPageOfItems = (names) => {
        const nums = names.map((n) => (store.items[n] ? Number(store.items[n].page) : NaN)).filter((n) => Number.isFinite(n) && pageIndexOf(n) !== -1);
        return nums.length ? Math.min.apply(null, nums) : null;
    };
    // Show page `pageNum`: resolve its dynamic labels, values and choice
    // labels via the batched OpenCPU endpoint against the persisted answers
    // first — but wait no longer than PAGE_RESOLVE_WAIT_MS: slow content (a
    // note_iframe's R Markdown, a heavy label) keeps its placeholder and
    // fills in when it arrives. Every page works this way, the first one
    // included.
    const goToPage = async (pageNum) => {
        const idx = pageIndexOf(pageNum);
        if (idx === -1) return;
        await Promise.race([
            resolveAndSubstitutePage(pageNum),
            new Promise((done) => setTimeout(done, PAGE_RESOLVE_WAIT_MS)),
        ]);
        showPage(idx);
        try { history.pushState(null, '', `?page=${pageNum}`); } catch (e) { /* noop */ }
    };

    // Single-flight guard: a rapid double-tap / Enter-repeat on Next must not
    // fire two page POSTs. Solo already guards via the controller's
    // submittingPage flag; this also covers default mode and the Enter-submit
    // path. Cleared once the in-flight submit settles (success advances to a new
    // page; failure leaves the participant free to retry).
    let pageSubmitInFlight = false;
    const submitPage = async () => {
        if (pageSubmitInFlight) return;
        pageSubmitInFlight = true;
        try { return await submitPageInner(); }
        finally { pageSubmitInFlight = false; }
    };

    // --- Incremental autosave (spec: persist on advance/blur, rate limited) ---
    // Fires from `change` (commit) events — a natural proxy for "on advance"
    // (solo: each answer precedes its advance) and "on blur" (default: text
    // commits on blur). Rate-limited to at most one request per SAVE_MIN_INTERVAL
    // (spec: <=1/20s) with a trailing flush so the last change always lands.
    // Best-effort and silent: failures are swallowed because the explicit
    // page-submit (+ offline queue) is the durable path. Files are excluded
    // (collectPayload drops them on the JSON path); they ride the explicit submit.
    const SAVE_MIN_INTERVAL = 20000;
    let lastSaveAt = 0, saveTimer = null, savePending = false, saveInFlight = false;
    // "Leave with unsaved changes?" guard — armed ONLY while a change is pending
    // (un-flushed), removed the instant a flush starts. A `beforeunload` listener
    // disqualifies the back/forward cache on iOS Safari / Android Chrome, so
    // confining it to the throttle window (rather than the whole session) keeps
    // Back instant whenever there's nothing at risk. The pagehide/sendBeacon
    // path still saves the data if they leave anyway, so this is purely the
    // user-facing prompt.
    let unloadGuarded = false;
    const beforeUnloadGuard = (e) => { e.preventDefault(); e.returnValue = ''; return ''; };
    const updateUnloadGuard = () => {
        if (savePending && !unloadGuarded) {
            window.addEventListener('beforeunload', beforeUnloadGuard);
            unloadGuarded = true;
        } else if (!savePending && unloadGuarded) {
            window.removeEventListener('beforeunload', beforeUnloadGuard);
            unloadGuarded = false;
        }
    };
    const flushPersist = async () => {
        if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
        if (!savePending || saveInFlight || !saveUrl) return;
        const page = pages[currentIndex];
        if (!page) return;
        const payload = collectPayload(page);
        if (!payload.data || Object.keys(payload.data).length === 0) {
            savePending = false; updateUnloadGuard(); return;   // nothing to save — don't strand the guard
        }
        savePending = false;
        updateUnloadGuard();   // flush starting; keepalive completes the in-flight send
        saveInFlight = true;
        lastSaveAt = Date.now();
        try {
            const res = await fetch(saveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: JSON.stringify({ data: payload.data, item_views: payload.item_views, unit_session_id: unitSessionId }),
                keepalive: true,
            });
            // Surface a transient "Saved" pill on a real persist (the server
            // returns {status:'noop'} — still 200 — when nothing was written, so
            // gate on status==='saved' to avoid claiming a save that didn't happen).
            // Failures stay silent: the explicit page-submit + offline queue are
            // the durable path.
            if (res && res.ok) {
                const body = await res.json().catch(() => null);
                if (body && body.status === 'saved') setSaveState('saved', t('save.saved', 'Saved'));
            }
        } catch (e) { /* best-effort; explicit submit + offline queue are the net */ }
        finally { saveInFlight = false; }
    };
    const schedulePersist = () => {
        if (!saveUrl) return;
        savePending = true;
        updateUnloadGuard();   // a change is now pending → arm the leave guard
        const elapsed = Date.now() - lastSaveAt;
        if (elapsed >= SAVE_MIN_INTERVAL) {
            flushPersist();
        } else if (!saveTimer) {
            saveTimer = setTimeout(flushPersist, SAVE_MIN_INTERVAL - elapsed);
        }
    };
    if (saveUrl) {
        root.addEventListener('change', (e) => {
            const t = e.target;
            if (!(t instanceof Element) || !t.name) return;
            if (t.name.startsWith('_item_views')) return;   // tracking inputs, not answers
            if (t.type === 'file') return;                  // files ride the explicit submit
            schedulePersist();
        });
        // A breakoff right before leaving must not lose the last unsaved change.
        // sendBeacon survives unload where fetch may not; fall back to a keepalive flush.
        const beaconFlush = () => {
            if (!savePending || !saveUrl) return;
            const page = pages[currentIndex];
            if (!page) return;
            const payload = collectPayload(page);
            if (!payload.data || Object.keys(payload.data).length === 0) return;
            savePending = false;
            updateUnloadGuard();
            const body = JSON.stringify({ data: payload.data, item_views: payload.item_views, unit_session_id: unitSessionId });
            try {
                if (navigator.sendBeacon) {
                    navigator.sendBeacon(saveUrl, new Blob([body], { type: 'application/json' }));
                } else {
                    flushPersist();
                }
            } catch (e) { /* noop */ }
        };
        window.addEventListener('pagehide', beaconFlush);
        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'hidden') beaconFlush();
        });
    }

    // Solo step controller — one item per screen, Typeform-style. Created here
    // (not at the top) because it drives submitPage() at page boundaries and
    // calls showPage() for cross-page back-navigation. showPage()'s onPageShown
    // hook (above) seats it on the right step after every page swap; the
    // initial showPage() near the bottom of initForm fires the first seating.
    if (isSolo) {
        soloController = initSolo({
            root,
            pages,
            getCurrentIndex: () => currentIndex,
            showPage,
            submitPage,
            validate: validatePageAndShowFeedback,
            // Minimum-exposure gate (disabled submit button): the controller
            // should consult this before treating Enter/OK as a page submit.
            canSubmitPage,
            pageNav: {
                isLastPage: (pageEl) => nav().isLastShown(Number(pageEl.dataset.fmrPage)),
                previousPageIndex: (pageEl) => {
                    const prev = nav().previousOf(Number(pageEl.dataset.fmrPage));
                    return prev == null ? -1 : pageIndexOf(prev);
                },
            },
        });
    }

    // Drain triggers: browser-reported connectivity change + initial load (in
    // case the tab was reloaded with entries still pending).
    window.addEventListener('online', () => { drainQueue(); });
    drainQueue();

    // Register the service worker on v2 runs (offline caching, web push) and
    // hand it the sync URL. The served SW does not act on that message or on
    // the `form-v2-drain` Background Sync tag yet, so the queue drains only
    // page-side (the `online` listener above); nothing drains while the tab
    // is closed. Silent on failure (e.g. insecure context).
    //
    // SW is served by RunController::serviceWorkerAction at /{runName}/service-worker
    // (path-based deploy) or /service-worker (subdomain deploy). The controller
    // sets `Service-Worker-Allowed: /{runName}/` so the scope can cover the
    // whole run path; registering at /assets/common/js/service-worker.js
    // directly would scope to that dir and miss the form POSTs.
    const registerFormSW = async () => {
        // The SW backs TWO independent features: offline-sync (needs syncUrl) and
        // web push (needs a VAPID key). Register it when EITHER is in play —
        // gating only on syncUrl meant a push-enabled study with offline_mode='off'
        // never registered the SW, so `navigator.serviceWorker.ready` in the push
        // code hung and push silently broke. (FMR_REGISTER_SYNC_URL is still only
        // posted when there's a syncUrl to register.)
        if (!('serviceWorker' in navigator)) return;
        if (!syncUrl && !window.vapidPublicKey) return;
        try {
            const runUrlObj = new URL(runUrl || window.location.href);
            const siteOrigin = window.location.origin;
            const isPathBased = runUrlObj.origin === siteOrigin && runUrlObj.pathname !== '/';
            const swPath = isPathBased ? runUrlObj.pathname.replace(/\/$/, '') + '/service-worker' : '/service-worker';
            const scope = isPathBased ? runUrlObj.pathname : '/';
            const existing = await navigator.serviceWorker.getRegistration(scope);
            const reg = existing || await navigator.serviceWorker.register(swPath, { scope });
            const sw = reg.active || reg.waiting || reg.installing || navigator.serviceWorker.controller;
            if (sw && syncUrl) sw.postMessage({ type: 'FMR_REGISTER_SYNC_URL', url: syncUrl });
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

        // A PWA can only be installed when the run has a manifest. Without one the
        // <pwa-install> component falls back to probing /manifest.json at the
        // origin root (→ 404), so only create it when a manifest link is present.
        // (The AddToHomeScreen modal below still offers iOS "Add to Home Screen"
        // guidance, which doesn't need a manifest.)
        const manifestLink = document.querySelector('link[rel="manifest"]');

        // 1. <pwa-install> component (native beforeinstallprompt path)
        if (!pwaInstallEl && manifestLink) {
            pwaInstallEl = document.createElement('pwa-install');
            pwaInstallEl.setAttribute('manifest-url', manifestLink.href);
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
            // add-to-homescreen is a prebuilt IIFE that exports nothing and only
            // assigns the factory to window.AddToHomeScreen — the webpack default
            // import binding is undefined (calling it threw "is not a function").
            // Use the global, like v1's PWAInstaller does. (Thrown → caught below,
            // leaving addToHomeInstance null so the native <pwa-install> path still
            // works and only the iOS modal fallback degrades.)
            const adhsFactory = window.AddToHomeScreen;
            if (typeof adhsFactory !== 'function') throw new Error('AddToHomeScreen global unavailable');
            addToHomeInstance = adhsFactory({
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
            const prev = nav().previousOf(Number(pages[currentIndex].dataset.fmrPage));
            if (prev != null) goToPage(prev);
        });
    });

    // Block implicit form submit (Enter key) from doing a real POST. In solo
    // mode Enter on a single-line input should advance one step (onContinue),
    // not submit the whole page; default mode funnels straight to submitPage.
    root.addEventListener('submit', (e) => {
        e.preventDefault();
        if (soloController) soloController.onContinue();
        else submitPage();
    });

    initGeopoint(root);

    // --- Reactive showif via Alpine (Phase 3) ---
    // The Alpine `fmrForm` component + `x-showif` directive are registered
    // once at module load (showif/alpine.js). This call promotes the
    // server-emitted `data-showif="<transpiled-js>"` attribute to `x-showif`
    // and adds `x-data="fmrForm"` on the root form.
    promoteShowifAttributes(root);

    // Deferred-fill at initial load was the previous model's per-item
    // /form-fill resolver. Under the page-scoped model every page — the
    // first one included — carries data-fmr-fill-id placeholders with blank
    // values and resolves via /form-render-page when it is shown
    // (resolveAndSubstitutePage). The /form-fill endpoint itself is still live
    // and useful for one-off live re-triggers (e.g. an admin-driven retrigger
    // button) — we just don't fire it on every page-load anymore.

    // initButtonGroups + initMediaRecorders + tom-select wiring (plain
    // <select> and <input.select2add>) live in items/* modules — imports above.
    initButtonGroups(root);
    initRadioDeselect(root);
    initBotCheck(root);
    initRank(root);
    initMediaRecorders(root);
    window.fmrInitMediaRecorders = () => initMediaRecorders(root);
    initTomSelects(root);
    initChooseWeekdays(root);


    // Fill every input from the stored state before anything is shown, so a
    // reload looks like the moment before it: the same items, with their
    // answers. setInputValue only writes into empty inputs and never fakes
    // an answered timestamp.
    Object.keys(store.items).forEach((name) => {
        const s = store.items[name];
        if (s.value == null || s.value === '') return;
        const inp = root.querySelector(`[name="${CSS.escape(name)}"], [name="${CSS.escape(name)}[]"]`);
        const wrapper = inp && inp.closest('.form-group, .item');
        if (!wrapper) return;
        const target = setInputValue(wrapper, Array.isArray(s.value) ? s.value.join(', ') : s.value);
        if (!target) return;
        programmaticFill = true;
        try {
            target.dispatchEvent(new Event('input', { bubbles: true }));
            target.dispatchEvent(new Event('change', { bubbles: true }));
        } finally {
            programmaticFill = false;
        }
    });

    // The page to open: the one this browser remembered for this unit
    // session, else the first page with a visible item lacking an answer
    // (pageState). `?page=` is honoured only for a shown page at or before
    // that one — that is the history the Previous button wrote.
    {
        const ps = nav();
        const paramPage = Number(new URLSearchParams(window.location.search).get('page'));
        const initial = (paramPage && ps.isShown(paramPage) && paramPage <= ps.currentPage) ? paramPage : ps.currentPage;
        const idx = pageIndexOf(initial);
        showPage(idx === -1 ? 0 : idx);
        // Resolve the deferred values, labels and choice labels of the page
        // we landed on. Fire-and-forget: the page is already shown; this
        // just fills.
        const initialPageEl = pages[currentIndex];
        if (initialPageEl) resolveAndSubstitutePage(Number(initialPageEl.dataset.fmrPage));
    }

    window.addEventListener('popstate', () => {
        const p = Number(new URLSearchParams(window.location.search).get('page'));
        const ps = nav();
        const cur = Number(pages[currentIndex] && pages[currentIndex].dataset.fmrPage);
        // Browser Back must not bypass the per-study allow_previous flag: all
        // pages stay mounted in the DOM, so without this gate popstate lets a
        // participant revisit and edit already-submitted pages the Previous
        // button deliberately doesn't offer. Re-assert the current page in
        // history and stay put. Same for a page the showifs have emptied.
        if (!p || !ps.isShown(p) || (!allowPrevious && p < cur)) {
            if (cur) { try { history.pushState(null, '', `?page=${cur}`); } catch (e) { /* noop */ } }
            return;
        }
        const idx = pageIndexOf(p);
        if (idx !== -1) showPage(idx);
    });

    // RequestCookie + RequestPhone + admin "monkey bar" preview live in items/* modules.
    initRequestCookie(root);
    initRequestPhone(root);
    initAdminPreview({ root, getCurrentPage: () => pages[currentIndex] });
    // Timed / all-answered auto-submit (Submit_Item type_options) — v1 parity.
    initAutoSubmit({ root, pages, getCurrentIndex: () => currentIndex, submitPage });
    // Show "Page expired" modal when the unit-session expiry passes — same
    // behaviour as v1's ExpiryNotifier, vanilla port.
    initExpiryNotifier();

    // Logout-link click handler: wipe the offline queue before navigating.
    // This is the only wipe: the service worker's fetch handler is GET-only
    // and never sees /<run>/logout, so a participant who reaches logout from
    // outside the form bundle (or uninstalls the PWA) is not covered.
    document.addEventListener('click', (e) => {
        const a = e.target.closest && e.target.closest('a[href]');
        if (!a) return;
        let url;
        try { url = new URL(a.href, window.location.href); } catch { return; }
        // /<run>/logout on path-routed deploys, bare /logout on subdomain ones.
        if (!/(?:^|\/)logout\/?$/.test(url.pathname)) return;
        // Scope the wipe to the run being logged out: the queue DB is origin-
        // wide, and another run's undrained answers must survive this logout.
        const runPathPrefix = url.pathname.replace(/logout\/?$/, '');
        wipeQueueForRun(runPathPrefix).catch(() => {});
        forgetPage(unitSessionId);
    });

    // Test hook: exercise the real bundled page validator deterministically
    // (used by e2e to assert e.g. that a required, untouched visual_analog_scale
    // is blocked client-side, without needing a dedicated required-VAS run).
    // Harmless in prod — it's the same function the submit/solo paths call.
    window.fmrValidatePage = validatePageAndShowFeedback;
    // Same for the minimum-exposure submit gate (auto-submit's negative
    // data-timeout keeps the button disabled until |t| ms have elapsed).
    window.fmrCanSubmitPage = canSubmitPage;

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
