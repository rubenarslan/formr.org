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
    queuePut, queueGetAll, queueDelete, queueSupersede, queueRestampFinal, queueResetRetry, entryBelongsTo, postWrite,
    wipeQueueForRun, normalizeSyncEntry, isTransientFailure,
    entryState, entryIsDue, markRetryFailure, markNeedsAttention,
    STATE_PENDING, STATE_NEEDS_ATTENTION, STATE_BLOCKED,
} from './offline/queue.js';
import { initMediaRecorders } from './items/recorders.js';
import { initButtonGroups } from './items/button-groups.js';
import { initRadioDeselect } from './items/radio-deselect.js';
import { initBotCheck, resetBotChecks } from './items/bot-check.js';
import { initRank } from './items/rank.js';
import { initGeopoint } from './items/geopoint.js';
import { initTomSelects } from './items/tom-select.js';
import { initChooseWeekdays } from './items/choose-weekdays.js';
import { initRequestCookie } from './items/request-cookie.js';
import { initRequestPhone } from './items/request-phone.js';
import { initAdminPreview } from './items/admin-preview.js';
import { initExpiryNotifier } from './items/expiry-notifier.js';
import { initAutoSubmit } from './items/auto-submit.js';
import { t, escapeHtml, iconLabel } from './lib/i18n.js';
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
import { initSolo, blockedSubmitControl } from './solo/controller.js';
import { createStore, pageState, readRememberedPage, rememberPage, forgetPage } from './state.mjs';
import {
    collectAnswers, fieldKey, isItemHidden, hiddenShowifItemIds,
    dispatchProgrammatic, setProgrammaticValue, claimPendingShowifControls, disableOwnControl,
} from './lib/inputs.js';

function initForm() {
    const root = document.querySelector('.fmr-form-v2');
    if (!root) return;

    const pages = Array.from(root.querySelectorAll('[data-fmr-page]'));
    if (pages.length === 0) return;

    // First, before any item code disables a control of its own: what the
    // server rendered disabled in a showif item is the showif's to restore.
    claimPendingShowifControls(root);

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
        const shownNum = pages[i] ? Number(pages[i].dataset.fmrPage) : NaN;
        if (Number.isFinite(shownNum) && !requestedPages.has(shownNum)) resolvePage(shownNum);
        // Let per-item widgets react to leaving a page (e.g. recorders stop
        // a live recording so the mic isn't left hot on an unseen page).
        if (changed) {
            document.dispatchEvent(new CustomEvent('fmr:pagechange', { detail: { index: i } }));
        }
        const p = pages[i];
        const pageNum = p ? Number(p.dataset.fmrPage) : null;
        if (pageNum) rememberPage(unitSessionId, pageNum);
        updatePageProgress(pageNum);
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

    // "Page N of M" and the bar. In solo, the step controller owns progress
    // (per-item, not per-page) and focus; it re-seats stepping via
    // onPageShown. Called on every page swap and whenever a showif changes
    // which pages are shown (M changes with it).
    // `ps` (optional): a pageState already taken this frame — see the
    // showif refresh below, which passes one to both.
    const updatePageProgress = (pageNum, ps = nav()) => {
        if (isSolo || !pageNum) return;
        const { index, total } = ps.progress(pageNum);
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
    };

    // Every page carries the same navigation row (FormRenderer::renderPageNav):
    // Previous is shown only when there is a shown page before this one, and
    // the advance button reads Submit on the last shown page, Next before it
    // — an authored submit label (no data-fmr-nav-label) is left alone.
    const applyNav = (pageEl, ps = nav()) => {
        const pageNum = Number(pageEl.dataset.fmrPage);
        pageEl.querySelectorAll('[data-fmr-prev]').forEach((btn) => { btn.hidden = ps.previousOf(pageNum) == null; });
        const last = ps.isLastShown(pageNum);
        pageEl.querySelectorAll('[data-fmr-nav-label]').forEach((btn) => {
            const label = t(last ? 'nav.submit' : 'nav.next', last ? 'Submit' : 'Next');
            if (btn.tagName === 'INPUT') { btn.value = label; return; }
            // Replace the text only: the button's element children stay — the
            // icon, and the fuse bar items/auto-submit.js appends to a timed
            // bare `submit` item (textContent would wipe it).
            const icon = btn.querySelector(':scope > i');
            const children = Array.from(btn.children);
            btn.textContent = label + ' ';
            children.forEach((child) => btn.appendChild(child));
            if (icon) icon.className = 'fa ' + (last ? 'fa-check' : 'fa-arrow-right');
        });
    };
    // A showif toggle can change which pages are shown (and so which is
    // last, and how many there are); refresh the page's chrome.
    // Coalesced to one refresh per frame: a single answer can toggle many
    // showifs, and each refresh snapshots the whole form.
    let navRefreshQueued = false;
    root.addEventListener('fmr:showif-toggled', () => {
        if (navRefreshQueued) return;
        navRefreshQueued = true;
        requestAnimationFrame(() => {
            navRefreshQueued = false;
            const p = pages[currentIndex];
            if (!p) return;
            const ps = nav();   // one snapshot of the form for both
            applyNav(p, ps);
            updatePageProgress(Number(p.dataset.fmrPage), ps);
        });
    });

    // Answered-timing: stamp on first value change per item. Suppressed while
    // resolveAndSubstitutePage writes server-computed deferred-fill values —
    // those dispatch input/change for Alpine/showif sync but are not
    // participant answers and must not fake an answered timestamp.
    // One delegated listener (change bubbles) for every named field.
    let programmaticFill = false;
    root.addEventListener('change', (e) => {
        if (programmaticFill) return;
        const inp = e.target;
        if (!inp.matches || !inp.matches('input[name], select[name], textarea[name]')) return;
        if (inp.name.startsWith('_item_views')) return;
        const wrapper = inp.closest('.item');
        if (!wrapper) return;
        const ans = wrapper.querySelector('.item_answered');
        if (ans && !ans.value) ans.value = mysqlDatetime();
        const ansR = wrapper.querySelector('.item_answered_relative');
        if (ansR && !ansR.value) ansR.value = String(Math.round(performance.now()));
    });

    // Solo layout (layout=solo): the Typeform-style one-item-per-screen step
    // controller is initialized further down (after submitPage is defined, so
    // it can drive page submission at page boundaries). See ./solo/controller.js.

    // This document's id and write counter, sent with every autosave and
    // page submit: the server ignores an autosave numbered below a page
    // submit of the same document that has already landed (it was started
    // earlier, so its answers are older — FormSubmissionService::
    // autosaveIsStale).
    const formDocId = genUuid();
    let writeSeq = 0;
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
        // A file input cannot be pre-filled: after a reload it is empty even
        // when the item's upload is stored, and posting '' would store NULL
        // over it (a re-submit via Previous, missing-item routing, autosave).
        // So an item with a stored upload posts nothing unless a new file is
        // chosen.
        pageEl.querySelectorAll('input[type=file][name]:not([disabled])').forEach((inp) => {
            if (inp.files && inp.files.length > 0) return;
            if (inp.dataset.fmrStored === '1') return;
            const group = inp.closest('.form-group');
            if (group && group.classList.contains('optional')) data[fieldKey(inp.name).key] = '';
        });
        return { data, item_views: itemViews, doc: formDocId, seq: ++writeSeq };
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
    // missing (the column defaults, patch 104).
    const offlineModeEnabled = (root.dataset.offlineMode || 'on') !== 'off';
    const allowPrevious = (root.dataset.allowPrevious || 'off') !== 'off';
    // This form's /form-sync endpoint. Entries already on the device are
    // drained through it (and recognised as this form's) whatever the
    // setting says: turning offline mode off must not strand pages queued
    // while it was on.
    const ownSyncUrl = root.dataset.syncUrl || '';
    // When offline mode is off, new submissions are not queued — they fail
    // hard as if the endpoint didn't exist.
    const syncUrl = offlineModeEnabled ? ownSyncUrl : '';
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
    // Mark the file inputs whose item has an upload stored (the state block,
    // then every reply): see collectPayload and validation/feedback.js.
    // A required item's input carries `required`, which an empty file input
    // always fails natively: lift it while the upload is stored, put it back
    // once it is not (retracted by a showif, say).
    const markStoredUploads = () => {
        root.querySelectorAll('input[type=file][name]').forEach((inp) => {
            const item = store.items[fieldKey(inp.name).key];
            if (item && item.answered) {
                inp.dataset.fmrStored = '1';
                if (inp.required) { inp.dataset.fmrRequired = '1'; inp.required = false; }
            } else {
                delete inp.dataset.fmrStored;
                if (inp.dataset.fmrRequired === '1') { inp.required = true; delete inp.dataset.fmrRequired; }
            }
        });
    };
    markStoredUploads();
    // Is a queue entry this form's — its run and its unit session
    // (entryBelongsTo)? `belongsHere`: this run's, any unit session.
    const ownKey = { sync_url: ownSyncUrl, unit_session_id: unitSessionId };
    const isOwnEntry = (e) => entryBelongsTo(e, ownKey);
    const belongsHere = (e) => entryBelongsTo(e, ownKey, { anyUnitSession: true });

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
                    visible: !isItemHidden(el),
                    answerable: !!(name && store.items[name] && store.items[name].answerable),
                };
            }),
    }));
    const nav = () => pageState(snapshotPages(), store, readRememberedPage(unitSessionId), allowPrevious);
    const pageIndexOf = (pageNum) => pages.findIndex((p) => Number(p.dataset.fmrPage) === Number(pageNum));
    // The ids of every item the form currently hides by showif, all pages:
    // posted with each submit so the server's record of hidden items — and
    // its final required check — agree with what the participant saw.
    const hiddenItemIds = () => hiddenShowifItemIds(root);

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
            root.insertBefore(queueBanner, root.firstChild);
        }
        // A refusal the participant must act on is announced at once
        // (alert); progress and success politely (status).
        queueBanner.setAttribute('role', variant === 'danger' ? 'alert' : 'status');
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
    // A call made while a drain is running waits for it and then gets one
    // more pass (the running drain took its snapshot of the queue before the
    // caller's entry existed). Returning early instead let submitPageInner's
    // `await drainQueue()` see entries the running drain was about to send,
    // queue a live page behind them, and wait for the 60 s retry.
    //
    // `scope` 'own' drains only this form's entries (its run and unit
    // session) — what a live page submit waits for; other studies' entries
    // on this origin are none of its business. 'all' (the triggers) drains
    // every group. A pass requested while one runs gets the wider scope.
    let drainPromise = null;
    let drainAgain = null;
    const drainQueue = (scope = 'all') => {
        if (drainPromise) {
            drainAgain = (drainAgain === 'all' || scope === 'all') ? 'all' : 'own';
            return drainPromise;
        }
        drainPromise = (async () => {
            let pass = scope;
            try {
                while (pass) {
                    drainAgain = null;
                    await drainQueueInner(pass === 'own');
                    pass = drainAgain;
                }
            } finally {
                drainPromise = null;
            }
        })();
        return drainPromise;
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
    // normalizeSyncEntry, not the raw entry: /form-sync 400s a client_ts that
    // isn't a MySQL DATETIME (it used to 500 at INSERT time, after the answers
    // were already applied — the client then retried and double-wrote). A 400
    // blocks the entry, so one stored by an older bundle is fixed up here.
    const postQueueEntry = (entry, entrySyncUrl) => postWrite(entrySyncUrl, normalizeSyncEntry(entry));

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
        // 403 {closed}: the study is not open (Run::admits) — the session is
        // fine, so retry later rather than asking the participant to sign in.
        if (res.status === 403 && body && body.closed) return 'server';
        if (res.status === 403) return 'session';
        if (res.status === 400) return 'invalid';
        return 'server';
    };

    const drainQueueInner = async (ownOnly) => {
        let entries;
        try { entries = await queueGetAll(); } catch (e) { return; }
        if (!entries || !entries.length) { hideQueueBanner(); return; }
        // Oldest first. client_ts has one-second resolution, so pages queued
        // within the same second fall back to `queued_at` (ms) — IDB returns
        // entries in uuid order, i.e. at random, and a later page must never
        // apply first (its hidden-item snapshot is newer, and a final page would end
        // the form without the earlier one).
        // A final page always goes last within its unit session: it ends the
        // form, and anything applied after it is answered 409 drop_entry —
        // lost. By time alone, a page re-submitted after the final page was
        // queued (fixing a needs_attention entry, or a revisit via Previous)
        // sorted after it.
        entries.sort((a, b) => ((a.final ? 1 : 0) - (b.final ? 1 : 0))
            || (a.client_ts || '').localeCompare(b.client_ts || '')
            || ((a.queued_at || 0) - (b.queued_at || 0)));
        // Scope the drain per run AND unit session: one run's rejection
        // (expired session, validation error) must not head-of-line block
        // another run's entries on this origin, and an entry left over from
        // an earlier unit session of this run (a previous diary entry — the
        // server can only drop it) must not block the current one. Legacy
        // entries without sync_url belong to this form.
        // This form's own entries: its run and its unit session (or none
        // recorded — legacy). Only they may merge into this page's store or
        // move this page on.
        const groups = new Map();
        entries.forEach((entry) => {
            const url = entry.sync_url || ownSyncUrl;
            const usid = Number(entry.unit_session_id) || 0;
            if (!url || (ownOnly && !isOwnEntry({ sync_url: url, unit_session_id: usid }))) return;
            const key = url + '#' + usid;
            if (!groups.has(key)) groups.set(key, { url, usid, entries: [] });
            groups.get(key).entries.push(entry);
        });
        const dueCount = [...groups.values()].reduce((n, g) => n + g.entries.filter((e) => entryIsDue(e)).length, 0);
        if (dueCount) {
            showQueueBanner(t('queue.syncing', 'Syncing %n queued submission(s)…', { n: dueCount }), 'warning');
        }
        let ownRedirect = null;
        let ownMissing = null;
        for (const { url: entrySyncUrl, usid, entries: groupEntries } of groups.values()) {
            const outcome = await drainRunGroup(groupEntries, entrySyncUrl, isOwnEntry({ sync_url: entrySyncUrl, unit_session_id: usid }));
            if (outcome === 'offline') {
                // A server that can't be reached while the device counts as
                // online (refused connection, DNS, a captive portal) fires no
                // `online` event: keep retrying on the timer, or the queue
                // waits for a reload.
                scheduleQueueRetry(30 * 1000);
                return;
            }
            if (outcome && outcome.redirect) ownRedirect = outcome.redirect;
            if (outcome && outcome.missing) ownMissing = outcome.missing;
        }
        await renderQueueSummary(ownRedirect);
        if (ownMissing && !ownRedirect) await routeToMissing(ownMissing);
    };

    // Drain one group (a run's entries for one unit session) oldest-first.
    // Returns 'offline' (abort every group), or {redirect, missing} for this
    // form's final page.
    const drainRunGroup = async (groupEntries, entrySyncUrl, isOwnForm) => {
        let redirect = null;
        let missing = null;
        for (const entry of groupEntries) {
            if (!entryIsDue(entry)) {
                // An entry that cannot go yet — rejected, backing off or
                // blocked — halts ITS run's queue: a later page must never
                // land ahead of an earlier one (its hidden-item snapshot is
                // newer; the final page's redirect would bounce the
                // participant out of the form).
                break;
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
                // The stored state of what just landed — for this form's
                // showifs.
                if (isOwnForm) absorbReply(body);
                // If the drain applied the final page of the form, the server
                // returns a redirect so Run::exec can advance. Taken by
                // renderQueueSummary once nothing else is pending for THIS run
                // — another run's redirect would hijack the participant out of
                // the form they're on.
                if (body && body.redirect && isOwnForm) redirect = body.redirect;
                // A final page that landed while a required item elsewhere is
                // still unanswered: the form is open; route the participant.
                if (isOwnForm && body && body.missing && Object.keys(body.missing).length) missing = body.missing;
                // A final page the server had already applied (its reply was
                // lost): the form may have ended meanwhile, and only the
                // server knows where the run goes — reload the run URL.
                if (isOwnForm && entry.final && body && body.already_applied && !redirect) redirect = runUrl || window.location.pathname;
                continue;
            }
            if (outcome === 'drop') {
                // Session ended / different unit — the server says this entry
                // can never apply. Stop retrying it.
                await queueDelete(entry.uuid);
                // When it is THIS page's unit session that is no longer
                // current, the page is stale too: the form ended (a final
                // page whose reply was lost lands here, not on
                // already_applied — the unit-session gate answers before the
                // uuid dedup) or the run moved on. Only the server knows
                // where the participant is; go there, as a live submit's 409
                // does.
                if (isOwnForm && unitSessionId && Number(entry.unit_session_id) === unitSessionId) {
                    redirect = runUrl || window.location.pathname;
                }
                continue;
            }
            if (outcome === 'errors') {
                // Validation failure: the server applied nothing (ledger row
                // rolled back). KEEP the answers; the participant fixes the
                // page and re-submits it, which supersedes this entry.
                markNeedsAttention(entry, body.errors);
                await queuePut(entry).catch(() => {});
                break;
            }
            if (outcome === 'throttled') break; // 429: retry the run later, no attempt counted
            const status = res.status;
            const message = (body && body.error) ? String(body.error) : '';
            if (outcome === 'invalid') {
                // 400 envelope error — re-POSTing the same bytes can't succeed.
                // It stops its run's queue like any entry that cannot go: a
                // later page must not land (or end the form) without it. The
                // banner offers Retry and Discard.
                markRetryFailure(entry, { reason: 'invalid', status, message, permanent: true });
                await queuePut(entry).catch(() => {});
                break;
            }
            if (outcome === 'session') {
                // No run session for this run: every entry of the run fails
                // the same way, so back them all off together instead of
                // burning one attempt per entry per drain.
                for (const e of groupEntries) {
                    if (!entryIsDue(e)) continue;
                    markRetryFailure(e, { reason: 'session', status, message });
                    await queuePut(e).catch(() => {});
                }
                break;
            }
            // 5xx / unexpected: back this entry off and leave the rest of the
            // run for the next drain (a sick server won't take them either).
            markRetryFailure(entry, { reason: 'server', status, message });
            await queuePut(entry).catch(() => {});
            break;
        }
        return (redirect || missing) ? { redirect, missing } : null;
    };

    // After a drain: take this run's redirect if its queue is clear, else
    // explain what's still on disk and why. Priority: a page the participant
    // must fix > entries we gave up on (Retry button) > entries in backoff
    // (auto-retry timer + Retry button) > everything sent.
    const renderQueueSummary = async (ownRedirect) => {
        let remaining;
        try { remaining = await queueGetAll(); } catch (e) { return; }
        // Only this unit session's entries hold the redirect back: a leftover
        // from an earlier unit session of the run can never apply, and must
        // not keep the participant on a form the server has ended.
        const ownRemaining = remaining.filter(isOwnEntry);
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
        const retryAction = { label: t('queue.retry', 'Retry now'), onClick: async (btn) => {
            btn.disabled = true;
            btn.textContent = t('queue.retrying', 'Retrying…');
            try { await queueResetRetry(); } catch (e) { /* drain re-renders */ }
            drainQueue();
        } };
        // A blocked entry halts its run's queue (pages land in order), so the
        // participant must be able to let it go: the answers on that page are
        // lost, the pages after it are sent.
        const discardAction = (entries) => (entries.length ? { label: t('queue.discard', 'Discard and continue'), onClick: async (btn) => {
            if (!window.confirm(t('queue.discard_confirm', 'Discard the answers that could not be sent (%n page(s))? They will be lost; the pages after them are sent.', { n: entries.length }))) return;
            btn.disabled = true;
            for (const e of entries) { await queueDelete(e.uuid).catch(() => {}); }
            drainQueue();
        } } : null);
        const attention = remaining.filter((e) => entryState(e) === STATE_NEEDS_ATTENTION);
        if (attention.length) {
            // Its errors are rendered onto this page's items, so only an
            // entry of this unit session qualifies.
            const own = attention.find(isOwnEntry);
            // One of an earlier unit session of this run (a previous diary
            // entry) can never apply — the participant can only let it go.
            const stale = attention.filter((e) => belongsHere(e) && !isOwnEntry(e));
            if (own) surfaceNeedsAttention(own);
            else if (stale.length) showQueueBanner(t('queue.needs_attention_stale', 'A page from an earlier visit to this study could not be saved and can no longer be sent.'), 'danger', [discardAction(stale)]);
            else showQueueBanner(t('queue.needs_attention_other_run', 'A queued submission for another study on this device was rejected. Open that study to fix it.'), 'danger');
            return;
        }
        const blocked = remaining.filter((e) => entryState(e) === STATE_BLOCKED);
        if (blocked.length) {
            const session = blocked.some((e) => e.last_error && e.last_error.reason === 'session');
            showQueueBanner(session
                ? t('queue.blocked_session', '%n queued submission(s) could not be sent because your session has expired. Open your personal study link to sign in again, then retry.', { n: blocked.length })
                : t('queue.blocked', '%n queued submission(s) could not be sent after several attempts. Your answers are kept on this device — please retry, or contact the study team if this persists.', { n: blocked.length }),
            'danger', [retryAction, discardAction(blocked.filter(belongsHere))].filter(Boolean));
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
        const idx = pageIndexOf(pageNum);
        const errors = entry.errors || {};
        const markErrors = () => {
            clearCustomValidity(pages[idx]);
            applyErrors(pages[idx], errors);
            resetBotChecks(root, Object.keys(errors));
            if (soloController && idx === currentIndex) soloController.onErrors();
        };
        // Name the page as the progress label counts it ("Page 2 of 4"):
        // the stored page number skips pages the showifs leave out.
        const ps = nav();
        const shownNum = ps.isShown(pageNum) ? ps.progress(pageNum).index : pageNum;
        const actions = [];
        if (idx !== -1 && idx === currentIndex) {
            markErrors();
        } else if (idx !== -1) {
            actions.push({
                label: t('queue.go_to_page', 'Go to page %page', { page: shownNum }),
                onClick: async () => { await goToPage(pageNum); markErrors(); },
            });
        }
        showQueueBanner(t('queue.needs_attention', 'Your answers for page %page could not be saved: please fix the highlighted answers and submit the page again.', { page: shownNum }), 'danger', actions);
    };

    // --- Page-transition resolver (POST /form-render-page) ---
    // Resolves the upcoming page's dynamic labels + values via the batched
    // OpenCPU endpoint. Called between submit-success and showPage so the
    // participant sees the resolved content as soon as the new page appears.
    // No-op for pages without any dynamic items (no data-fmr-fill-id /
    // -label-id placeholders) — short-circuits with no network.
    const PAGE_RESOLVE_WAIT_MS = 800;
    // A submit waits this long for its page's resolution still in flight
    // (see resolvePage); after that the page goes as it is, and what could
    // not be loaded is submitted empty.
    const SUBMIT_RESOLVE_WAIT_MS = 15000;
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
        // A range always holds a value (the browser's midpoint when nothing
        // is set), so it counts as empty until the participant has moved it.
        const empty = target.type === 'range'
            ? !(wrapper.querySelector('.item_answered') || {}).value
            : target.value === '';
        if (!empty || val === '') return null; // already answered / nothing to write
        if (target.tomselect) {
            // select_or_add: a tom-select built on a text input does not
            // follow a written .value — the preset would post unseen.
            const ts = target.tomselect;
            const values = ts.settings.maxItems === 1 ? [val] : val.split(/\s*,\s*/).filter(Boolean);
            values.forEach((v) => {
                if (!ts.options[v]) ts.addOption({ [ts.settings.valueField]: v, [ts.settings.labelField]: v });
            });
            ts.setValue(values, true);
            return target;
        }
        target.value = val;
        return target;
    };
    const pageByNum = (pageNum) => pages.find((p) => Number(p.dataset.fmrPage) === Number(pageNum));
    const hasDynamicContent = (targetPage) => !!(targetPage
        && targetPage.querySelector('[data-fmr-fill-id], [data-fmr-label-id], [data-fmr-choice-id]'));
    // The POST behind a page's resolution, against the answers as they are
    // now. Resolves {res, body}; rejects on a network failure.
    const fetchPageResolution = async (pageNum) => {
        const res = await fetch(renderPageUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ page: Number(pageNum), answers: collectAllAnswers() }),
        });
        return { res, body: await res.json().catch(() => null) };
    };
    // `fetched`: a fetchPageResolution already under way for this page.
    const resolveAndSubstitutePage = async (pageNum, fetched = null) => {
        const targetPage = pageByNum(pageNum);
        if (!hasDynamicContent(targetPage)) return;
        const fillItems = targetPage.querySelectorAll('[data-fmr-fill-id]');
        const labelItems = targetPage.querySelectorAll('[data-fmr-label-id]');
        const choiceSpans = targetPage.querySelectorAll('[data-fmr-choice-id]');
        // Mark items pending while we wait — visual feedback.
        fillItems.forEach((el) => el.classList.add('fmr-fill-pending'));
        labelItems.forEach((el) => el.classList.add('fmr-fill-pending'));
        const settleChoices = () => choiceSpans.forEach((el) => el.classList.remove('fmr-fill-pending'));
        let body;
        try {
            let res;
            ({ res, body } = await (fetched || fetchPageResolution(pageNum)));
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
        // Substitute values (setInputValue). Don't clobber a value the
        // participant already gave (back-nav case).
        fillItems.forEach((wrapper) => {
            wrapper.classList.remove('fmr-fill-pending');
            const id = wrapper.dataset.fmrFillId;
            if (!(id in valuesMap)) return;
            // A stored answer — an emptied field or a deselected choice too —
            // is the participant's: setInputValue only sees an empty input,
            // so without this the R default came back over it on every
            // revisit/reload and the next submit stored the default.
            const storedName = itemNameOf(wrapper);
            const stored = storedName ? store.items[storedName] : null;
            const participantFacing = !!wrapper.querySelector('input[name]:not([type=hidden]):not([name^="_item_views"]), select[name], textarea[name]');
            // `saved`, not `answered`: an emptied field or a deselected
            // choice is stored with no value (answered: false; answered
            // implies saved).
            if (stored && stored.saved && participantFacing) return;
            const target = setInputValue(wrapper, valuesMap[id]);
            if (!target) return;
            programmaticFill = true;
            try {
                // Marked, so listeners outside this module (the
                // all-answered auto-submit) can tell it from an answer.
                dispatchProgrammatic(target);
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
    // for /form-render-page.
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
    // blockedSubmitControl is shared with solo/controller.js. Exposed as
    // `window.fmrCanSubmitPage`.
    const canSubmitPage = (pageEl) => {
        const p = pageEl || pages[currentIndex];
        return !!p && !blockedSubmitControl(p);
    };
    // Refuse + draw the eye to the still-disabled button (the fuse bar shows
    // how long is left). Returns true when the submit may proceed.
    const gateSubmit = (pageEl) => {
        const p = pageEl || pages[currentIndex];
        if (!p) return false;
        const btn = blockedSubmitControl(p);
        if (!btn) return true;
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
        return false;
    };

    const submitPageInner = async () => {
        const page = pages[currentIndex];
        if (!gateSubmit(page)) return;
        const inFlight = pageResolutions.get(Number(page.dataset.fmrPage));
        if (inFlight) {
            setSaveState('saving', t('save.saving', 'Saving…'), 0);
            await Promise.race([inFlight, new Promise((done) => setTimeout(done, SUBMIT_RESOLVE_WAIT_MS))]);
        }
        clearCustomValidity(page);
        if (!validatePageAndShowFeedback(page)) {
            // The wait above may have raised "Saving…" (no autohide): nothing
            // is being saved.
            savePill.classList.remove('is-visible');
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
        // The next page's R content does not depend on this submit's reply
        // (it resolves against the answers posted now): fetch it alongside
        // the POST, so the page transition waits for the slower of the two
        // rather than for both in turn. It is written into the page only if
        // the reply leads there — after a rejected submit, values resolved
        // from the rejected answers must not stick in the next page's inputs.
        let nextFetch = null;
        if (nextPage != null && hasDynamicContent(pageByNum(nextPage))) {
            nextFetch = fetchPageResolution(nextPage);
            nextFetch.catch(() => {}); // unused → no unhandled rejection
        }
        const fetchedFor = (n) => (nextFetch && Number(n) === Number(nextPage) ? nextFetch : null);

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

        // An earlier page of this form still waiting in the queue must land
        // first: try to send it now, and if it is still there, queue this
        // page behind it instead of overtaking it (a later page carries a
        // newer hidden-item snapshot, and the final page ends the form).
        let queuedBehind = false;
        if (ownSyncUrl) {
            await drainQueue('own');
            const waiting = await queueGetAll().catch(() => []);
            queuedBehind = waiting.some((e) => isOwnEntry(e) && Number(e.page) !== pageNum);
        }

        // Let an autosave already on the wire land before this page does
        // (see saveInFlightRequest); its failure is irrelevant here.
        // Bounded: a stalled autosave must not leave Next dead.
        if (saveInFlightRequest) {
            await Promise.race([
                saveInFlightRequest.catch(() => {}),
                new Promise((done) => setTimeout(done, AUTOSAVE_WAIT_MS)),
            ]);
        }

        let res, resBody, netErr;
        if (queuedBehind) {
            netErr = new Error('queued behind an earlier page');
        } else try {
            ({ res, body: resBody } = await postWrite(submitUrl, useMultipart ? Object.assign({ files }, payload) : payload));
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
        // Offline mode off, and this page only held back because an earlier
        // one of this form is still waiting: say so (the connection is fine),
        // and let the participant retry or let that page go.
        if (queuedBehind && !syncUrl) {
            savePill.classList.remove('is-visible');
            const waiting = t('queue.waiting_first', 'An earlier page is still waiting to be sent. This page can be submitted once it has gone.');
            // The summary banner carries the waiting page's own actions
            // (Retry, Discard, Go to page): keep it, and say on top of it why
            // this page did not go.
            await renderQueueSummary(null);
            if (queueBanner && !queueBanner.hidden) {
                const note = document.createElement('div');
                note.className = 'fmr-queue-banner-note';
                note.textContent = waiting;
                queueBanner.insertBefore(note, queueBanner.firstChild);
            } else {
                showQueueBanner(waiting, 'warning', [{ label: t('queue.retry', 'Retry now'), onClick: async (btn) => {
                    btn.disabled = true;
                    try { await queueResetRetry(); } catch (e) { /* drain re-renders */ }
                    drainQueue('own');
                } }]);
            }
            return;
        }
        const QUEUE_FILE_SIZE_CAP_MB = (window.formr?.form_v2?.offline_blob_max_mb) || 10;
        const QUEUE_FILE_SIZE_CAP = QUEUE_FILE_SIZE_CAP_MB * 1024 * 1024;
        if (isTransientFailure(netErr, res)) {
            const oversizedFile = useMultipart
                ? fileInputs.find((inp) => inp.files[0] && inp.files[0].size > QUEUE_FILE_SIZE_CAP)
                : null;
            if (!syncUrl || oversizedFile) {
                savePill.classList.remove('is-visible');
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
                doc: payload.doc,
                seq: payload.seq,
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
            try { await queuePut(entry); } catch (e) {
                savePill.classList.remove('is-visible');
                console.error('enqueue failed', e);
                window.alert(t('error.queue_failed', 'Your submission could not be queued locally. Please check your connection and try again.'));
                return;
            }
            // This submission replaces any earlier queued copy of the same page
            // (a rejected `needs_attention` one, or a plain re-visit): the old
            // bytes must not drain after — or instead of — the new ones.
            try { await queueSupersede(entry, entry.uuid); } catch (e) { /* best effort */ }
            if (!entry.final) {
                try { await queueRestampFinal(entry, entry.hidden); } catch (e) { /* best effort */ }
            }
            // The `online` event drains it on reconnect; a server error fires
            // no `online` event, so also retry on a timer.
            scheduleQueueRetry(15000);
            setSaveState('offline', t('save.offline', 'Saved offline'), 2500);
            showQueueBanner(queuedBehind
                ? t('queue.queued_behind', 'An earlier page is still waiting to be sent. This page is queued behind it and will be sent right after.')
                : t('queue.queued_offline', "You're offline. This submission is queued and will be sent when you reconnect."), 'warning');
            // Advance locally so the participant can continue — the next
            // drain applies in order server-side. The store counts the posted
            // answers as given, so navigation moves on exactly as after a live
            // submit. If this was the last page, leave them on it; the run's
            // next unit is only known once the sync drains and redirects.
            store.markQueued(payload.data, payload.hidden);
            const queuedNext = nav().nextAfter(pageNum);
            // Awaited, as after a live submit: the single-flight guard must
            // hold until the next page is shown, or a second tap re-queues
            // this page.
            if (queuedNext != null) await goToPage(queuedNext, fetchedFor(queuedNext));
            return;
        }
        if (!res.ok) {
            console.error('page-submit HTTP', res.status);
            // Drop the saving pill — error UI takes over.
            savePill.classList.remove('is-visible');
            const errBody = resBody;
            if (errBody && errBody.stale_session) {
                // This page was rendered for an earlier unit session (form
                // continued elsewhere / a new diary entry started). Reload so
                // the server re-renders the current state.
                window.alert(t('error.stale_session', 'This form was continued elsewhere or a new entry has started. The page will reload to catch up.'));
                window.location.reload();
                return;
            }
            if (res.status === 403 && errBody && errBody.closed) {
                // The study was closed meanwhile: the run's page says so.
                window.location.reload();
                return;
            }
            if (res.status === 403) {
                // Run session gone — most likely the login session expired.
                window.alert(t('error.session_expired', 'Your session has expired. The page will reload so you can continue.'));
                window.location.reload();
                return;
            }
            if (res.status === 409) {
                // The form is no longer the current unit (it ended — perhaps
                // a queued final page landed first — or the run moved on).
                // The server knows where the participant is: go there.
                window.location.href = runUrl || window.location.pathname;
                return;
            }
            window.alert(t('error.generic', 'Sorry, something went wrong. Please try again.'));
            return;
        }
        // A 200 that was redirected to a login/consent page (session expiry on
        // deployments where auth redirects instead of 403ing) parses as null
        // below — both cases mean "this tab's session is no longer valid".
        const body = resBody;
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
            resetBotChecks(root, Object.keys(body.errors || {}));
            if (soloController) soloController.onErrors();
            return;
        }
        // The server accepted this page: any earlier queued copy of it (e.g. a
        // rejected `needs_attention` entry the participant just fixed) is now
        // stale and must not drain over the accepted answers. Then re-run the
        // drain so the queue banner reflects what's left (hides when empty).
        if (ownSyncUrl) {
            queueSupersede({ sync_url: ownSyncUrl, unit_session_id: unitSessionId, page: pageNum })
                .then((removed) => { if (removed) drainQueue(); })
                .catch(() => {});
        }
        cancelPendingAutosave();
        // The reply carries the stored state of what was just written: merge
        // it into the store and hand the values to the showif scope.
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
        if (body.missing && Object.keys(body.missing).length && await routeToMissing(body.missing)) {
            return;
        }
        const next = nav().nextAfter(pageNum);
        if (next != null) {
            await goToPage(next, fetchedFor(next));
            return;
        }
        // Nothing follows and the server did not end the form: the document
        // and the server disagree (the form's items changed since it was
        // rendered). Reload and let the server render the current state.
        window.location.href = runUrl || window.location.pathname;
    };

    // The final page is stored, but a required item elsewhere is still
    // unanswered (a showif revealed it after its page was submitted, say):
    // take the participant to the first such page and mark the items. The
    // same for a live submit and a drained queue entry.
    const routeToMissing = async (missing) => {
        const first = firstPageOfItems(Object.keys(missing));
        if (first == null) return false;
        await goToPage(first);
        applyErrors(pages[currentIndex], missing);
        if (soloController) soloController.onErrors();
        return true;
    };

    // Merge a submit / sync reply into the store and the showif scope.
    const absorbReply = (body) => {
        if (!body || typeof body !== 'object') return;
        if (body.items) { store.merge(body.items); markStoredUploads(); }
        const values = {};
        Object.keys(body.items || {}).forEach((name) => { values[name] = body.items[name].value; });
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
    // The page's resolution, kept while in flight: a page is shown after at
    // most PAGE_RESOLVE_WAIT_MS, and a submit must not go before the R values
    // it carries (a hidden bridge item's) have arrived — the late reply would
    // fill an input nothing sends again.
    const pageResolutions = new Map();
    // Pages whose resolution this document has asked for: showPage resolves
    // any other page it opens (browser Back, solo Back, the queue banner's
    // "Go to page"), so no way onto a page leaves its R content blank.
    const requestedPages = new Set();
    const resolvePage = (pageNum, fetched = null) => {
        const n = Number(pageNum);
        requestedPages.add(n);
        const p = resolveAndSubstitutePage(n, fetched).catch(() => {});
        pageResolutions.set(n, p);
        p.then(() => { if (pageResolutions.get(n) === p) pageResolutions.delete(n); });
        return p;
    };
    // The history URL of a page: the current query with `page` set. The rest
    // of the query stays (a `?code=` link must remain the resumable link).
    const pageHref = (pageNum) => {
        const params = new URLSearchParams(window.location.search);
        params.set('page', String(pageNum));
        return '?' + params.toString();
    };
    // `fetched`: this page's fetchPageResolution, already under way (a
    // submit starts the next page's alongside its POST).
    const goToPage = async (pageNum, fetched = null) => {
        const idx = pageIndexOf(pageNum);
        if (idx === -1) return;
        await Promise.race([
            resolvePage(pageNum, fetched),
            new Promise((done) => setTimeout(done, PAGE_RESOLVE_WAIT_MS)),
        ]);
        showPage(idx);
        try { history.pushState(null, '', pageHref(pageNum)); } catch (e) { /* noop */ }
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
    // How long a page submit waits for an autosave already on the wire.
    const AUTOSAVE_WAIT_MS = 5000;
    let lastSaveAt = 0, saveTimer = null, savePending = false;
    // The in-flight autosave request (resolves once the server has replied,
    // i.e. written). A page submit waits for it: an autosave sent before the
    // participant's last edit and landing after the submit would overwrite
    // the submitted answer with the older one.
    let saveInFlightRequest = null;
    // "Leave with unsaved changes?" guard — armed ONLY while a change is pending
    // (un-flushed), removed the instant a flush starts. A `beforeunload` listener
    // disqualifies the back/forward cache on iOS Safari / Android Chrome, so
    // confining it to the throttle window (rather than the whole session) keeps
    // Back instant whenever there's nothing at risk. The pagehide/sendBeacon
    // path still saves the data if they leave anyway, so this is purely the
    // user-facing prompt.
    // (add/removeEventListener with the same function are idempotent.)
    const beforeUnloadGuard = (e) => { e.preventDefault(); e.returnValue = ''; return ''; };
    const updateUnloadGuard = () => {
        if (savePending) window.addEventListener('beforeunload', beforeUnloadGuard);
        else window.removeEventListener('beforeunload', beforeUnloadGuard);
    };
    // The JSON body of an autosave of the current page, or null when it has
    // no answers. doc + seq: the server drops this autosave if a page submit
    // of this document numbered above it has already landed.
    const autosaveBody = () => {
        const page = pages[currentIndex];
        const payload = page ? collectPayload(page) : null;
        if (!payload || !payload.data || Object.keys(payload.data).length === 0) return null;
        return JSON.stringify({ data: payload.data, item_views: payload.item_views, unit_session_id: unitSessionId, doc: payload.doc, seq: payload.seq });
    };
    const flushPersist = async () => {
        if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
        if (!savePending || saveInFlightRequest || !saveUrl) return;
        // A page submit on the wire carries every answer of this page; an
        // autosave sent now would land after it — into a form its final
        // submit has ended. It stays pending: cancelled if the submit
        // succeeds, sent with the next change (or the pagehide beacon) if not.
        if (pageSubmitInFlight) return;
        const body = autosaveBody();
        savePending = false;
        // Nothing to save: don't strand the guard. Otherwise the flush is
        // starting, and keepalive completes the in-flight send.
        updateUnloadGuard();
        if (body === null) return;
        lastSaveAt = Date.now();
        try {
            saveInFlightRequest = fetch(saveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body,
                keepalive: true,
            });
            const res = await saveInFlightRequest;
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
        finally { saveInFlightRequest = null; }
    };
    // A page submit carries every answer of its page, so an autosave still
    // waiting for its throttle window is superseded — and must not go out
    // afterwards (the pagehide beacon would post it into a form the final
    // submit has already ended).
    const cancelPendingAutosave = () => {
        if (saveTimer) { clearTimeout(saveTimer); saveTimer = null; }
        savePending = false;
        updateUnloadGuard();
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
            // A value the page wrote itself (an R-resolved `value`) is not
            // the participant's change; it is posted with the page submit.
            if (programmaticFill) return;
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
            const body = autosaveBody();
            if (body === null) return;
            savePending = false;
            updateUnloadGuard();
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

    // Register the service worker when the run is a PWA (it has a VAPID key:
    // web push, the manifest, the install items) — the same condition as
    // v1's pwa-register.js. The offline queue does not need it: it drains
    // page-side (the `online` listener above), so nothing drains while the
    // tab is closed, and a study without a manifest gets no worker at all.
    // Silent on failure (e.g. insecure context).
    //
    // SW is served by RunController::serviceWorkerAction at /{runName}/service-worker
    // (path-based deploy) or /service-worker (subdomain deploy). The controller
    // sets `Service-Worker-Allowed: /{runName}/` so the scope can cover the
    // whole run path; registering at /assets/common/js/service-worker.js
    // directly would scope to that dir and miss the form POSTs.
    const registerFormSW = async () => {
        if (!('serviceWorker' in navigator) || !window.vapidPublicKey) return null;
        try {
            const runUrlObj = new URL(runUrl || window.location.href);
            const siteOrigin = window.location.origin;
            const isPathBased = runUrlObj.origin === siteOrigin && runUrlObj.pathname !== '/';
            const swPath = isPathBased ? runUrlObj.pathname.replace(/\/$/, '') + '/service-worker' : '/service-worker';
            const scope = isPathBased ? runUrlObj.pathname : '/';
            const existing = await navigator.serviceWorker.getRegistration(scope);
            const reg = existing || await navigator.serviceWorker.register(swPath, { scope });
            // Same explicit update check as v1's pwa-register.js: iOS
            // standalone PWAs don't reliably re-check the worker script on
            // their own, so a v2-only study would keep an old worker.
            if (existing) existing.update().catch(() => {});
            return reg;
        } catch (e) {
            console.warn('form-v2 SW registration failed', e);
            return null;
        }
    };
    const formSWRegistration = registerFormSW();

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
        // Announced (marked): a showif over the status and the autosave see
        // it; the auto-submit / solo auto-advance don't take it for an answer.
        if (hidden) { hidden.setCustomValidity(''); setProgrammaticValue(hidden, value); }
        if (status) status.textContent = t('pwa.install.using_installed', 'You are using the installed app.');
        if (btn) {
            disableOwnControl(btn);
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-success');
            btn.innerHTML = iconLabel('fa-check', t('pwa.install.installed', 'Installed'));
        }
        wrapper.closest('.form-group')?.classList.add('formr_answered');
    };

    const setAtsStatus = (wrapper, value, text) => {
        const hidden = wrapper.querySelector('input');
        const status = wrapper.querySelector('.status-message');
        setProgrammaticValue(hidden, value);
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
                    setAtsStatus(w, 'not_added', t('pwa.install.failed', 'Installation didn\'t complete. You can try again or add to home screen manually from your browser menu.'));
                    const btn = w.querySelector('.add-to-homescreen');
                    if (btn) btn.innerHTML = iconLabel('fa-plus-square', t('pwa.install.add_button', 'Add to Home Screen'));
                });
                try { pwaInstallEl.hideDialog(); } catch (e) {}
            });
            pwaInstallEl.addEventListener('pwa-user-choice-result-event', (e) => {
                const accepted = e?.detail?.userChoiceResult === 'accepted';
                root.querySelectorAll('.add-to-homescreen-wrapper').forEach((w) => {
                    if (accepted) {
                        markAddedToHomeScreen(w, 'added');
                    } else {
                        setAtsStatus(w, 'not_added', t('pwa.install.dismissed', 'Install dismissed. You can add the app to your home screen at any time.'));
                        const btn = w.querySelector('.add-to-homescreen');
                        if (btn) btn.innerHTML = iconLabel('fa-plus-square', t('pwa.install.add_button', 'Add to Home Screen'));
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

    // One element per PWA item: its inner wrapper, or the item's form-group
    // when it has none. The two selectors match both for every item, and
    // wiring both attached every handler twice (two install modals, two
    // subscriptions and POSTs per click).
    const pwaItemWrappers = (wrapperSel, groupSel) => Array.from(root.querySelectorAll(`${wrapperSel}, ${groupSel}`))
        .filter((el) => !(el.matches(groupSel) && el.querySelector(wrapperSel)));

    pwaItemWrappers('.add-to-homescreen-wrapper', '.form-group.item-add_to_home_screen').forEach((wrapper) => {
        if (isStandaloneDisplayMode()) {
            markAddedToHomeScreen(wrapper, 'already_added');
            return;
        }
        const required = wrapper.closest('.form-group')?.classList.contains('required');
        const hidden = wrapper.querySelector('input');
        if (required && hidden && !['added', 'already_added'].includes(hidden.value)) {
            hidden.setCustomValidity(t('pwa.install.required', 'Please add this study to your home screen to continue.'));
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
                        t('pwa.install.no_support', 'Your browser doesn\'t support adding this study to the home screen. Try Safari (iOS), Chrome on Android, or Edge on desktop.'));
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
                setAtsStatus(wrapper, 'prompted', t('pwa.install.preparing', 'Preparing installation…'));
                btn.innerHTML = iconLabel('fa-spinner fa-spin', t('pwa.processing', 'Processing…'));
            } else {
                setAtsStatus(wrapper, 'not_prompted',
                    t('pwa.install.not_prompted', 'Your browser hasn\'t offered an install prompt. Try a supported browser (Chrome on Android, Edge on desktop, Safari on iOS).'));
            }
        });
    });

    // --- PWA: PushNotification item ---
    // Subscribe via the SW's pushManager + window.vapidPublicKey, then POST
    // the subscription JSON to /{run}/ajax_save_push_subscription. Hidden
    // input tracks state across reloads; every write to it goes through
    // setProgrammaticValue, so showifs and the autosave follow it. On iOS this only works inside the
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
            await registration.showNotification(t('pwa.push.test_title', 'Test notification'), {
                body: t('pwa.push.test_body', 'This is a test notification. If you can see this, notifications are working!'),
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
        // Translated strings carry the list markup (<ol>/<li>/<strong>).
        let html = '';
        if (/iphone|ipad|ipod/.test(ua)) {
            html = t('pwa.push.help_ios', '<ol><li>Open <strong>Settings</strong> → <strong>Notifications</strong>.</li>'
                 + '<li>Find Safari (or your browser) and enable <strong>Allow Notifications</strong>.</li>'
                 + '<li>Make sure <strong>Focus</strong> mode isn\'t blocking notifications.</li>'
                 + '<li>For home-screen apps, check <strong>Settings → Screen Time → Content & Privacy Restrictions</strong>.</li>'
                 + '<li>Reload this page after changing settings.</li></ol>');
        } else if (/android/.test(ua)) {
            html = t('pwa.push.help_android', '<ol><li>Open <strong>Settings → Apps</strong>, find your browser.</li>'
                 + '<li>Tap <strong>Notifications</strong> and ensure they\'re <strong>Allowed</strong>.</li>'
                 + '<li>Check that <strong>Do Not Disturb</strong> isn\'t blocking notifications.</li>'
                 + '<li>Some manufacturers have battery-optimization settings that suppress notifications — disable them for your browser.</li>'
                 + '<li>Try installing this study to your home screen for better notification support.</li>'
                 + '<li>Reload this page after changing settings.</li></ol>');
        } else if (/macintosh/.test(ua)) {
            html = t('pwa.push.help_mac', '<ol><li>Open <strong>System Settings → Notifications</strong>.</li>'
                 + '<li>Find your browser and ensure <strong>Allow Notifications</strong> is on.</li>'
                 + '<li>Check that <strong>Do Not Disturb</strong> / <strong>Focus</strong> is off.</li>'
                 + '<li>Reload this page after changing settings.</li></ol>');
        } else if (/windows/.test(ua)) {
            html = t('pwa.push.help_windows', '<ol><li>Open <strong>Settings → System → Notifications</strong>.</li>'
                 + '<li>Ensure your browser has notifications turned on.</li>'
                 + '<li>Check that <strong>Focus assist</strong> isn\'t blocking them.</li>'
                 + '<li>Reload this page after changing settings.</li></ol>');
        } else {
            html = '<p>' + escapeHtml(t('pwa.push.help_other', 'Check your operating system\'s notification settings to make sure your browser is allowed to send notifications.')) + '</p>';
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
            toggleBtn.textContent = t('pwa.push.help_hide', 'Hide troubleshooting tips');
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
        if (showTest) buttons.push('<button type="button" class="btn btn-default fmr-push-test">' + iconLabel('fa-bell', t('pwa.push.test_button', 'Test notification')) + '</button>');
        if (showUnsub) buttons.push('<button type="button" class="btn btn-warning fmr-push-unsubscribe">' + iconLabel('fa-bell-slash', t('pwa.push.disable_button', 'Disable notifications')) + '</button>');
        buttons.push('<button type="button" class="btn btn-link fmr-push-help-toggle">' + escapeHtml(t('pwa.push.help_show', 'Show troubleshooting tips')) + '</button>');
        // customMessage is plain text (escaped); additionalContent is markup
        // built by the caller from translated strings.
        status.innerHTML =
            (msg ? `<p>${escapeHtml(msg)}</p>` : '') +
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
                    toggleBtn.textContent = t('pwa.push.help_show', 'Show troubleshooting tips');
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
            if (unsubBtn) unsubBtn.innerHTML = iconLabel('fa-spinner fa-spin', t('pwa.processing', 'Processing…'));
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
            setProgrammaticValue(hidden, 'permission_denied');
            if (status) status.innerHTML = '<p>' + escapeHtml(t('pwa.push.unsubscribed', 'You have unsubscribed from notifications. Click the button to enable them again.')) + '</p>';
            if (btn) {
                btn.disabled = false;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-primary');
                btn.innerHTML = iconLabel('fa-bell', t('pwa.push.enable_button', 'Enable Notifications'));
            }
            wrapper.closest('.form-group')?.classList.remove('formr_answered');
        } catch (e) {
            console.error('unsubscribe failed', e);
            if (unsubBtn) unsubBtn.innerHTML = iconLabel('fa-bell-slash', t('pwa.push.disable_button', 'Disable notifications'));
        }
    };

    const markPushSubscribed = (wrapper, subscription, opts = {}) => {
        const hidden = wrapper.querySelector('input[type="text"]');
        const btn = wrapper.querySelector('button.push-notification-permission');
        if (hidden) {
            hidden.setCustomValidity('');
            setProgrammaticValue(hidden, JSON.stringify(subscription));
        }
        if (btn) {
            disableOwnControl(btn);
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-success');
            btn.innerHTML = iconLabel('fa-check', t('pwa.push.enabled_button', 'Notifications Enabled'));
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

    pwaItemWrappers('.push-notification-wrapper', '.form-group.item-push_notification').forEach(async (wrapper) => {
        const hidden = wrapper.querySelector('input[type="text"]');
        const status = wrapper.querySelector('.status-message');
        const btn = wrapper.querySelector('button.push-notification-permission');
        const required = wrapper.closest('.form-group')?.classList.contains('required');
        if (required && hidden && hidden.value === 'not_requested') {
            hidden.setCustomValidity(t('pwa.push.required', 'Please complete this required step before continuing.'));
        }
        if (!btn) return;

        // Step 1: iOS-but-not-standalone — push only works inside the
        // installed PWA. Tell the participant + disable the button + reword
        // the gating customValidity so the inline-error path explains the
        // install requirement (vs. the generic "complete this step").
        if (isIOSDevice() && !isStandaloneDisplayMode()) {
            if (hidden) {
                setProgrammaticValue(hidden, 'not_supported');
                if (required) {
                    hidden.setCustomValidity(t('pwa.push.ios_install_required', 'Please add this study to your home screen first, then open it from the home screen icon to enable notifications.'));
                }
            }
            if (status) status.innerHTML = '<div class="alert alert-warning" role="alert">' + t('pwa.push.ios_install_first', '<strong>Add to Home Screen first.</strong> On iOS, push notifications only work after you install this study to your home screen and open it from there.') + '</div>';
            disableOwnControl(btn);
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
            setProgrammaticValue(hidden, 'not_supported');
            if (status) status.innerHTML = '<div class="alert alert-warning" role="alert">' + escapeHtml(t('pwa.push.unsupported', 'Sorry, your browser does not support push notifications.')) + '</div>';
            setBlockedValidity(t('pwa.push.unsupported_validity', 'This browser does not support push notifications. Please use a supported browser to continue.'));
            disableOwnControl(btn);
            return;
        }
        if (!window.vapidPublicKey) {
            setProgrammaticValue(hidden, 'not_supported');
            if (status) status.innerHTML = '<div class="alert alert-warning" role="alert">' + escapeHtml(t('pwa.push.not_configured', 'Push notifications are not configured for this study.')) + '</div>';
            setBlockedValidity(t('pwa.push.not_configured_validity', 'Push notifications are not configured for this study. Contact the study administrator.'));
            disableOwnControl(btn);
            return;
        }
        if (!isIOSCompatibleVersion()) {
            setProgrammaticValue(hidden, 'not_supported');
            if (status) status.innerHTML = '<div class="alert alert-warning" role="alert">' + escapeHtml(t('pwa.push.ios_version', 'Sorry, push notifications require iOS 16.4 or later.')) + '</div>';
            setBlockedValidity(t('pwa.push.ios_version_validity', 'Push notifications require iOS 16.4 or later. Please update your device to continue.'));
            disableOwnControl(btn);
            return;
        }

        // Step 3: SW must be registered (the form bundle does this).
        // `ready` never rejects — it stays pending when no worker ever
        // activates (registration refused, install failed) — so bound the
        // wait, or the fallback below is unreachable and the button is never
        // wired.
        let registration;
        try {
            if (await formSWRegistration) {
                registration = await Promise.race([
                    navigator.serviceWorker.ready,
                    new Promise((done) => setTimeout(() => done(null), 20000)),
                ]);
            }
        } catch (e) {}
        if (!registration || !registration.pushManager) {
            setProgrammaticValue(hidden, 'not_supported');
            if (status) status.innerHTML = '<div class="alert alert-warning" role="alert">' + escapeHtml(t('pwa.push.no_sw', 'Service worker not registered. Please reload the page and try again.')) + '</div>';
            setBlockedValidity(t('pwa.push.no_sw_validity', 'Service worker is not registered. Reload the page and try again.'));
            disableOwnControl(btn);
            return;
        }

        // Step 4: rehydrate previous subscription state.
        let alreadySubscribed = false;
        try {
            const existing = await registration.pushManager.getSubscription();
            if (existing) {
                markPushSubscribed(wrapper, existing);
                renderNotificationControls(wrapper, registration, {
                    customMessage: t('pwa.push.enabled', 'Push notifications are enabled.'),
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
                        customMessage: t('pwa.push.enabled', 'Push notifications are enabled.'),
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
            setProgrammaticValue(hidden, 'permission_denied');
            setBlockedValidity(t('pwa.push.blocked_validity', 'Notifications are blocked. Enable them in your browser/system settings, then tap the button again.'));
            renderNotificationControls(wrapper, registration, {
                showTestButton: false,
                customMessage: t('pwa.push.declined_retry', 'You have declined push notifications. You can enable them in your browser settings, then click the button below.'),
            });
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-default');
            btn.innerHTML = iconLabel('fa-times', t('pwa.push.blocked_retry_button', 'Notifications blocked — click after enabling in browser settings'));
        } else {
            // Default permission state — clear instruction.
            if (status) status.innerHTML = '<p>' + escapeHtml(t('pwa.push.click_to_enable', 'Click the button to enable push notifications.')) + '</p>';
            btn.innerHTML = iconLabel('fa-bell', t('pwa.push.enable_button', 'Enable Notifications'));
        }

        // Step 6: click handler.
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            if (btn.disabled && !btn.classList.contains('btn-default')) return; // disabled-success state
            const original = btn.innerHTML;
            btn.innerHTML = iconLabel('fa-spinner fa-spin', t('pwa.processing', 'Processing…'));
            try {
                // Re-check existing subscription (participant may have
                // accepted via another tab).
                const existing = await registration.pushManager.getSubscription();
                if (existing) {
                    markPushSubscribed(wrapper, existing);
                    await savePushSubscriptionToServer(existing);
                    renderNotificationControls(wrapper, registration, {
                        customMessage: t('pwa.push.already_enabled', 'Push notifications are already enabled.'),
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
                        ? '<p>' + t('pwa.push.android_note', '<strong>Note for Android users:</strong> on some devices, you may need to restart your browser or add this study to your home screen for notifications to work properly.') + '</p>'
                        : '';
                    renderNotificationControls(wrapper, registration, {
                        customMessage: saved
                            ? t('pwa.push.enabled_success', 'Push notifications enabled successfully!')
                            : t('pwa.push.enabled_not_saved', 'Push notifications enabled locally — couldn\'t save the subscription on the server. Reload to retry.'),
                        additionalContent: '<p>' + escapeHtml(t('pwa.push.test_sent', 'A test notification was sent. If you didn\'t see it, your system settings might be blocking it.')) + '</p>' + androidNote,
                        showUnsubscribeButton: true,
                    });
                    await sendTestNotification(registration);
                } else if (result.reason === 'permission_denied') {
                    setProgrammaticValue(hidden, 'permission_denied');
                    setBlockedValidity(t('pwa.push.declined_validity', 'You declined push notifications. Enable them in your browser/system settings, then reload to try again.'));
                    btn.classList.remove('btn-primary');
                    btn.classList.add('btn-default');
                    btn.innerHTML = iconLabel('fa-times', t('pwa.push.blocked_button', 'Notifications blocked'));
                    disableOwnControl(btn);
                    renderNotificationControls(wrapper, registration, {
                        showTestButton: false,
                        customMessage: t('pwa.push.declined', 'You declined push notifications. You can enable them in your browser settings later.'),
                    });
                } else if (result.reason === 'invalid_config') {
                    setProgrammaticValue(hidden, 'not_supported');
                    setBlockedValidity(t('pwa.push.not_configured_validity', 'Push notifications are not configured for this study. Contact the study administrator.'));
                    if (status) status.innerHTML = '<div class="alert alert-warning" role="alert">' + escapeHtml(t('pwa.push.config_error', 'Server configuration error. Please contact the study administrator.')) + '</div>';
                    btn.innerHTML = iconLabel('fa-exclamation-triangle', t('pwa.push.config_error_button', 'Configuration error'));
                    disableOwnControl(btn);
                } else {
                    setProgrammaticValue(hidden, 'not_supported');
                    // Subscription failed — most often this is iOS Safari
                    // refusing to subscribe outside a home-screen-installed
                    // PWA. Surface the install hint either way; on Android /
                    // desktop it's still applicable advice.
                    setBlockedValidity(t('pwa.push.failed_validity', 'We couldn\'t enable push notifications. If you\'re on iOS, make sure you\'ve added this study to your home screen and opened it from there.'));
                    const iosHint = isIOSDevice()
                        ? ' ' + escapeHtml(t('pwa.push.failed_ios_hint', 'On iOS, push notifications only work after you\'ve added this study to your home screen and opened it from there.'))
                        : '';
                    if (status) status.innerHTML = '<div class="alert alert-warning" role="alert"><strong>' + escapeHtml(t('pwa.push.failed_title', 'Couldn\'t enable push notifications.')) + '</strong>'
                        + iosHint + ' ' + escapeHtml(t('pwa.push.failed_retry_later', 'Please try again later or contact the study administrator.')) + '</div>';
                    btn.innerHTML = iconLabel('fa-exclamation-triangle', t('pwa.push.error_button', 'Error — try again'));
                }
            } catch (err) {
                console.error('push subscription click failed', err);
                btn.innerHTML = original;
                setBlockedValidity(t('pwa.push.unexpected_validity', 'We hit an unexpected error enabling push notifications. Please reload and try again.'));
                if (status) status.innerHTML = '<div class="alert alert-warning" role="alert"><strong>' + escapeHtml(t('pwa.push.failed_title', 'Couldn\'t enable push notifications.')) + '</strong> '
                    + escapeHtml(t('pwa.push.failed_reload', 'Please reload and try again, or contact the study administrator if this keeps happening.')) + '</div>';
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


    // Stored answers are already in the inputs: FormRenderer renders each
    // one through its item's own preset logic, so a reload looks like the
    // moment before it.
    //
    // The page to open: the one this browser remembered for this unit
    // session, else the first page with a visible item lacking an answer
    // (pageState). `?page=` is honoured only for a shown page at or before
    // that one — that is the history the Previous button wrote. Visibility
    // is the showifs' result, so the choice waits until Alpine has started
    // and its first evaluations have settled (they resolve asynchronously);
    // choosing earlier would see every showif item as hidden and step over
    // a page made of them.
    let initialPageOpened = false;
    const openInitialPage = () => {
        if (initialPageOpened) return;
        initialPageOpened = true;
        const ps = nav();
        const paramPage = Number(new URLSearchParams(window.location.search).get('page'));
        // Without allow_previous an earlier page is never offered (the
        // popstate guard below): a stale `?page=` (a copied or restored URL)
        // must not reopen a submitted page either.
        const initial = (paramPage && allowPrevious && ps.isShown(paramPage) && paramPage <= ps.currentPage) ? paramPage : ps.currentPage;
        const idx = pageIndexOf(initial);
        // showPage resolves the deferred values, labels and choice labels of
        // the page we land on. Fire-and-forget: the page is already shown;
        // this just fills.
        showPage(idx === -1 ? 0 : idx);
        // Give the landing entry its page, so browser Back from a later page
        // returns here (popstate refuses an entry without one).
        const landed = Number(pages[currentIndex].dataset.fmrPage);
        if (landed) { try { history.replaceState(null, '', pageHref(landed)); } catch (e) { /* noop */ } }
        // Test-side signal that the form is ready: every listener is attached
        // and the participant is on their page. waitForBundle() in
        // tests/e2e/helpers/v2Form.js polls for window.fmrFormReady.
        window.fmrFormReady = true;
        // Nothing to show on any page (a form of automatic items only —
        // random, ip, get…): send the final submit, which stores them and
        // ends the form, instead of an empty page with a Submit button.
        // Only once the showifs have run (not from the no-Alpine fallback,
        // where every showif item still reads hidden), and only if it still
        // holds once the page's R values have arrived — a showif reading one
        // can reveal items.
        if (alpineStarted && ps.shownPages.length === 0) {
            Promise.resolve(pageResolutions.get(landed)).then(() => setTimeout(() => {
                if (nav().shownPages.length === 0) submitPage();
            }, 0));
        }
    };
    let alpineStarted = false;
    document.addEventListener('alpine:initialized', () => { alpineStarted = true; setTimeout(openInitialPage, 0); }, { once: true });
    // Should Alpine never start (a broken bundle), still open a page.
    setTimeout(openInitialPage, 3000);

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
            if (cur) { try { history.pushState(null, '', pageHref(cur)); } catch (e) { /* noop */ } }
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
        forgetPage(unitSessionId);
        const wiped = wipeQueueForRun(runPathPrefix).catch(() => {});
        // A plain click navigates this document away, which can abort the
        // IndexedDB transactions mid-wipe (nothing else wipes: the service
        // worker never sees /logout). Hold the navigation until the wipe
        // settles — bounded, so a wedged IDB can't trap the participant.
        // Modified clicks / new-tab targets keep this page alive anyway.
        const plainClick = e.button === 0 && !e.defaultPrevented
            && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey
            && (!a.target || a.target === '_self');
        if (!plainClick) return;
        e.preventDefault();
        Promise.race([wiped, new Promise((done) => setTimeout(done, 1500))])
            .then(() => { window.location.href = a.href; });
    });

    // Test hook: exercise the real bundled page validator deterministically
    // (used by e2e to assert e.g. that a required, untouched visual_analog_scale
    // is blocked client-side, without needing a dedicated required-VAS run).
    // Harmless in prod — it's the same function the submit/solo paths call.
    window.fmrValidatePage = validatePageAndShowFeedback;
    // Same for the minimum-exposure submit gate (auto-submit's negative
    // data-timeout keeps the button disabled until |t| ms have elapsed).
    window.fmrCanSubmitPage = canSubmitPage;

    // window.fmrFormReady is set by openInitialPage (above), once the
    // participant is on their page.
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
