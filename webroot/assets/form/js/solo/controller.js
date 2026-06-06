// Typeform-style "solo" step controller for form_v2 (layout=solo).
//
// Replaces the original patch-066 CSS-scroll-snap skin, which broke down on
// multi-page forms (nested 100vh scroll containers), had no explicit
// forward/back affordance, auto-advanced on the first `change` of a
// multi-checkbox item, and let the sticky progress bar overlap content.
//
// Model: one *item* per screen. A "step" is a navigable `.form-group` in the
// currently-visible `.fmr-page`. Authored page boundaries still drive real
// submission — when the participant clicks OK past the last step of a page we
// call the host's `submitPage()` (validate → POST → showPage(next)), and
// `onPageShown()` re-seats the controller on the new page's first step. All
// items stay mounted, so Alpine `x-showif`, validation, r-calls, the offline
// queue and `allow_previous` keep their normal semantics; we only manage which
// single step is visible and the nav/progress chrome around it.
//
// Inner helpers are `function` declarations (hoisted) so the public surface can
// sit up top and the file reads in narrative order.

// Item types that render no participant-visible UI (pure hidden inputs filled
// server-side). They must never become their own blank screen.
const HIDDEN_TYPES = ['hidden', 'get', 'random', 'referrer', 'ip', 'browser', 'calculate', 'server'];

export function initSolo(opts) {
    const { root, pages, getCurrentIndex, showPage, submitPage, validate, allowPrevious } = opts;

    let current = null;          // the active step element (.form-group)
    let pendingGoLast = false;   // next onPageShown should land on the last step (back-nav)
    let advanceTimer = null;
    let transitioning = false;   // guards against double-advance mid-slide
    let submittingPage = false;  // guards against double page-submit at a boundary
    let maxPct = 0;              // progress bar is monotonic — see updateProgress

    const reduceMotion = window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const LEAVE_MS = 150;

    const progressBar = root.querySelector('[data-fmr-progress-bar]');
    const progressLabel = root.querySelector('[data-fmr-progress-label]');
    let navEl = null, backBtn = null, okBtn = null, hintEl = null, helpBtn = null;
    let loadingEl = null;


    // --- step discovery ------------------------------------------------------
    function directGroups(pageEl) {
        return Array.from(pageEl.querySelectorAll(':scope > .form-group'));
    }
    function isNavigable(el) {
        if (!el) return false;
        // `.hidden` is the showif signal (alpine.js toggles it); `[hidden]`
        // guards a not-yet-shown page section's children defensively.
        if (el.classList.contains('hidden') || el.hasAttribute('hidden')) return false;
        for (const t of HIDDEN_TYPES) {
            if (el.classList.contains('item-' + t)) return false;
        }
        return true;
    }
    function steps(pageEl) { return directGroups(pageEl).filter(isNavigable); }
    function nextStep(el) {
        const s = steps(pages[getCurrentIndex()]);
        const i = s.indexOf(el);
        return i === -1 ? null : (s[i + 1] || null);
    }
    function prevStep(el) {
        const s = steps(pages[getCurrentIndex()]);
        const i = s.indexOf(el);
        return i <= 0 ? null : s[i - 1];
    }
    function firstInvalidStep(pageEl) {
        for (const s of steps(pageEl)) {
            if (s.classList.contains('is-invalid') || s.querySelector('.is-invalid')) return s;
        }
        return null;
    }
    function isLastPage() { return getCurrentIndex() >= pages.length - 1; }
    function isTerminal(el) { return !nextStep(el) && isLastPage(); }

    // --- input classification ------------------------------------------------
    // Auto-advance on commit for single-choice radio groups, single <select>
    // (incl. tom-select), and range-type sliders. Checkboxes, multi-selects,
    // free text and textareas need an explicit OK.
    function autoAdvances(el) {
        if (!el) return false;
        if (el.querySelector('textarea')) return false;
        if (el.querySelector('input[type=checkbox]')) return false;
        if (el.querySelector('select[multiple]')) return false;
        // free-text-ish inputs (text/email/number/url/tel/date/…) stay manual
        if (el.querySelector('input:not([type=radio]):not([type=checkbox]):not([type=hidden]):not([type=range]):not([disabled])')) return false;
        return !!el.querySelector('input[type=radio]:not([disabled]), select:not([multiple]):not([disabled]), input[type=range]:not([disabled])');
    }
    // A plain single-choice radio card (mc / mc_button / rating_button / square)
    // — the ONLY kind where we hide OK (picking advances). Selects and sliders
    // keep OK visible because they may carry a default the participant accepts
    // without ever firing `change`.
    function isRadioChoice(el) {
        return !!el && !!el.querySelector('input[type=radio]:not([disabled])')
            && !el.querySelector('input[type=checkbox], select, textarea, input[type=range]')
            && !el.querySelector('input:not([type=radio]):not([type=checkbox]):not([type=hidden]):not([disabled])');
    }
    function hasSelection(el) {
        return !!el && !!el.querySelector('input[type=radio]:checked, input[type=checkbox]:checked');
    }
    // A step whose first control is a real text field (the kind that needs a
    // soft keyboard). Used for the iOS keyboard hand-off in onContinue.
    function leadingTextField(el) {
        return !!el && !!el.querySelector(
            'input:not([type=hidden]):not([disabled]):not([type=radio]):not([type=checkbox]):not([type=range]):not([type=button]):not([type=submit]):not([type=reset]), textarea:not([disabled])'
        );
    }
    function hintFor(el) {
        if (!el) return '';
        // `block` items are display-only guards backed by a zero-size required
        // checkbox the participant can't tick — their own red message IS the
        // instruction, so the "Choose all that apply" hint (triggered by that
        // hidden checkbox) is wrong. Suppress it.
        if (el.classList.contains('item-block')) return '';
        if (el.querySelector('textarea')) return 'Ctrl + Enter to continue';
        if (el.querySelector('input[type=checkbox]') || el.querySelector('select[multiple]')) {
            return 'Choose all that apply, then OK';
        }
        if (autoAdvances(el)) return '';
        if (el.querySelector('input:not([type=hidden]):not([type=radio]):not([type=checkbox]), select')) {
            return 'press Enter ↵';
        }
        return '';
    }
    // `sync` focuses the field synchronously (in the caller's call stack) so an
    // advancing tap/Enter can raise the iOS soft keyboard — iOS only opens the
    // keyboard for a focus() that runs inside the user-gesture task. Otherwise
    // (initial seat, auto-advance) focus is deferred as before.
    function focusFirst(el, sync) {
        const f = el.querySelector(
            'input:not([type=hidden]):not([disabled]):not([type=radio]):not([type=checkbox]), textarea:not([disabled])'
        ) || el.querySelector('input[type=radio]:not([disabled]), input[type=checkbox]:not([disabled])');
        // What to scroll into view: the focusable IF it's actually visible;
        // otherwise the visible control area (button-group / card items hide the
        // real radio and show .btn proxies — centring the hidden input would
        // leave the visible buttons under the chrome). Fall back to the controls
        // block, then the step.
        const visible = (f && f.getBoundingClientRect().height > 0)
            ? f
            : (el.querySelector('.controls-inner, .controls') || el);
        // When the step is scrollable (not fit-locked — e.g. a banner pushed
        // a short-screen step partly below the fold), centre the control in
        // the viewport so it lands clear of the fixed footer/nav instead of
        // sitting under them. scroll-padding (in _solo.scss) keeps the
        // clearance; on a fit-locked step this is a no-op (overflow:hidden).
        const reveal = () => {
            if (!document.documentElement.classList.contains('fmr-solo-locked')) {
                try { visible.scrollIntoView({ block: 'center', behavior: 'auto' }); } catch (e) { /* noop */ }
            }
        };
        if (sync && f) {
            try { f.focus({ preventScroll: true }); } catch (e) { /* noop */ }
            setTimeout(reveal, 60);
            return;
        }
        if (f || visible) setTimeout(() => {
            if (f) { try { f.focus({ preventScroll: true }); } catch (e) { /* noop */ } }
            reveal();
        }, 60);
    }

    // --- navigation ----------------------------------------------------------
    const ANIM = ['fmr-solo-enter-up', 'fmr-solo-enter-down', 'fmr-solo-leave-up', 'fmr-solo-leave-down'];
    function clearAnim(el) { if (el) el.classList.remove(...ANIM); }

    // Seat `el` as the current step. `dir` ('forward' | 'back') picks the
    // entrance direction (forward rises from below, back drops from above).
    // `sync` (set when seating directly from a user gesture) focuses the field
    // synchronously so iOS raises the keyboard — see focusFirst.
    function seat(el, dir, sync) {
        clearTimeout(advanceTimer); // cancel a pending auto-advance so a manual OK can't double-advance
        root.querySelectorAll('.form-group.fmr-solo-current').forEach((g) => {
            g.classList.remove('fmr-solo-current');
            clearAnim(g);
        });
        current = el;
        el.classList.add('fmr-solo-current');
        clearAnim(el);
        // Skip the entrance translate on a synchronous (keyboard-hand-off) seat:
        // the field is focused in the same tick and iOS raises the keyboard, so
        // a translateY(26px→0) slide would make the input visibly jump up as the
        // keyboard appears. A stationary field is the right call there; the slide
        // stays for every normal advance.
        if (!reduceMotion && !sync) {
            void el.offsetWidth;   // restart the animation
            el.classList.add(dir === 'back' ? 'fmr-solo-enter-down' : 'fmr-solo-enter-up');
        }
        updateNav();
        updateProgress();
        lockScrollToFit(el);
        try { window.scrollTo({ top: 0 }); } catch (e) { /* noop */ }
        focusFirst(el, sync);
        transitioning = false;
    }

    // #1: a step is `min-height:100vh`, so when its content fits it's exactly
    // one viewport and shouldn't scroll at all. But the entrance animation
    // translates the box down by 26px, which transiently extends the document
    // and lets the wheel/trackpad "bounce" ~26px before settling. Layout height
    // (offsetHeight) ignores the transform, so we can tell *now* whether the
    // settled step overflows: if it fits, lock body scroll (the transient
    // overflow is clipped, no phantom scrollbar); if it genuinely overflows
    // (e.g. a tall note/plot), leave scrolling enabled.
    function lockScrollToFit(el) {
        // Lock ONLY when the whole document fits the actually-visible area — so
        // the entrance animation's transient translateY can't wheel-bounce, but
        // content is NEVER trapped. Two corrections over the naive
        // "offsetHeight <= innerHeight":
        //   - measure against window.visualViewport.height: on iOS the URL/
        //     toolbar (and the keyboard) shrink the visible area below
        //     innerHeight, so innerHeight over-reports the room.
        //   - add in-flow chrome ABOVE the step (the audio/video "unverified"
        //     banner, the validation summary) via the step's document offsetTop.
        //     offsetTop/offsetHeight ignore transforms, so this stays correct
        //     during the entrance animation (unlike scrollHeight).
        // When a banner pushes a "fitting" step partly below the fold, the doc
        // no longer fits → we DON'T lock → the participant can scroll the input
        // out from under the fixed footer/nav. (This was the iOS overlap bug.)
        const usable = (window.visualViewport && window.visualViewport.height) || window.innerHeight;
        let top = 0;
        for (let n = el; n; n = n.offsetParent) top += n.offsetTop;
        const fits = !el || (top + el.offsetHeight) <= usable + 2;
        document.documentElement.classList.toggle('fmr-solo-locked', fits);
    }
    // The seat-time measurement misses content that grows *after* seating —
    // late-loading note images / OpenCPU feedback plots, font reflow, a showif
    // reveal. Re-run the fit check whenever the current step's rendered size
    // changes. ResizeObserver tracks content-box size, not transforms, so the
    // entrance animation never triggers it.
    if (window.ResizeObserver) {
        new ResizeObserver(() => { if (current) lockScrollToFit(current); }).observe(root);
    }
    window.addEventListener('resize', () => lockScrollToFit(current));

    // Animate the current step out (when it's actually on screen) before
    // seating the next, so navigation reads as a directional slide.
    function goTo(el, dir) {
        if (!el) return;
        dir = dir || 'forward';
        const old = current;
        if (old && old !== el && !reduceMotion && old.offsetParent !== null) {
            transitioning = true;
            clearAnim(old);
            void old.offsetWidth;
            old.classList.add(dir === 'back' ? 'fmr-solo-leave-down' : 'fmr-solo-leave-up');
            setTimeout(() => seat(el, dir), LEAVE_MS);
        } else {
            seat(el, dir);
        }
    }
    function onContinue(userGesture) {
        if (transitioning || submittingPage) return;
        const pageEl = pages[getCurrentIndex()];
        const nxt = nextStep(current);
        if (nxt) {
            if (current && !validate(current)) return;   // validate just this step
            // iOS keyboard hand-off: when a real tap/Enter advances to a
            // text-field step, seat it synchronously so we can focus the field
            // inside this gesture (the keyboard rises like tabbing to the next
            // field). Costs the slide animation on those transitions — a fair
            // trade for not making the participant tap the field separately.
            if (userGesture && leadingTextField(nxt)) {
                seat(nxt, 'forward', true);
                return;
            }
            goTo(nxt, 'forward');
            return;
        }
        // Last step of the page → validate the whole page so a showif-revealed
        // required item we never landed on still gets caught, then submit.
        if (!validate(pageEl)) {
            const bad = firstInvalidStep(pageEl);
            if (bad && bad !== current) goTo(bad, 'forward');
            return;
        }
        // Page boundary → a real submit (POST + OpenCPU resolve). Show a loading
        // cue if it runs longer than a moment, and guard against double-submit.
        submittingPage = true;
        const cueTimer = setTimeout(showLoading, 220);
        Promise.resolve(submitPage()).catch(() => {}).finally(() => {
            submittingPage = false;
            clearTimeout(cueTimer);
            hideLoading();
        });
    }
    // Page-boundary loading overlay (#7). Built lazily; shown only when a page
    // submit takes >220ms so fast transitions don't flash a spinner.
    function showLoading() {
        if (!loadingEl) {
            loadingEl = document.createElement('div');
            loadingEl.className = 'fmr-solo-loading';
            loadingEl.setAttribute('aria-hidden', 'true');
            loadingEl.innerHTML = '<div class="fmr-solo-spinner"></div>';
            root.appendChild(loadingEl);
        }
        loadingEl.classList.add('is-visible');
    }
    function hideLoading() { if (loadingEl) loadingEl.classList.remove('is-visible'); }
    function onBack() {
        if (transitioning) return;
        const prv = prevStep(current);
        if (prv) { goTo(prv, 'back'); return; }
        const idx = getCurrentIndex();
        if (allowPrevious && idx > 0) {
            pendingGoLast = true;
            showPage(idx - 1);     // host updates currentIndex + fires onPageShown
        }
    }
    function onPageShown(pageEl) {
        const s = steps(pageEl);
        const dir = pendingGoLast ? 'back' : 'forward';
        if (!s.length) { current = null; updateNav(); updateProgress(); return; }
        const target = pendingGoLast ? s[s.length - 1] : s[0];
        pendingGoLast = false;
        goTo(target, dir);
    }

    // --- chrome (nav footer + progress) -------------------------------------
    function buildNav() {
        navEl = document.createElement('div');
        navEl.className = 'fmr-solo-nav';
        // OK comes FIRST in the DOM so tabbing out of an input lands on OK (the
        // primary action), not Back. _solo.scss uses `order` to keep Back on the
        // visual left and OK on the right.
        navEl.innerHTML =
            '<div class="fmr-solo-advance">'
            + '<span class="fmr-solo-hint" aria-hidden="true"></span>'
            + '<button type="button" class="btn btn-primary fmr-solo-ok">OK <i class="fa fa-check" aria-hidden="true"></i></button>'
            + '</div>'
            + '<div class="fmr-solo-left">'
            + '<button type="button" class="fmr-solo-back" aria-label="Go to previous question">'
            + '<i class="fa fa-arrow-up" aria-hidden="true"></i> Back</button>'
            + '<button type="button" class="fmr-solo-help" aria-label="Show contact and privacy links" aria-expanded="false">'
            + '<i class="fa fa-question" aria-hidden="true"></i></button>'
            + '</div>';
        root.appendChild(navEl);
        backBtn = navEl.querySelector('.fmr-solo-back');
        okBtn = navEl.querySelector('.fmr-solo-ok');
        hintEl = navEl.querySelector('.fmr-solo-hint');
        helpBtn = navEl.querySelector('.fmr-solo-help');
        backBtn.addEventListener('click', (e) => { e.preventDefault(); onBack(); });
        okBtn.addEventListener('click', (e) => { e.preventDefault(); onContinue(true); });
        // The run footer (contact / privacy / ToS / settings) is hidden by
        // default in solo; the ? toggles it so it doesn't clutter every screen.
        helpBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const open = document.documentElement.classList.toggle('fmr-solo-footer-open');
            helpBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        // Mobile: when the soft keyboard opens, the layout viewport doesn't
        // shrink on iOS, so a `bottom:0` bar ends up behind the keyboard. Lift
        // the footer by the keyboard overlap using the visual-viewport API.
        const vv = window.visualViewport;
        if (vv) {
            const adjust = () => {
                const overlap = Math.max(0, window.innerHeight - (vv.height + vv.offsetTop));
                navEl.style.transform = overlap > 1 ? `translateY(${-overlap}px)` : '';
                // Keyboard open → release the fit-lock so a focused input that the
                // keyboard now covers can still be scrolled into view.
                if (overlap > 1) document.documentElement.classList.remove('fmr-solo-locked');
                else lockScrollToFit(current);
            };
            vv.addEventListener('resize', adjust);
            vv.addEventListener('scroll', adjust);
        }
    }
    function updateNav() {
        if (!navEl) buildNav();
        const canBack = !!prevStep(current) || (allowPrevious && getCurrentIndex() > 0);
        backBtn.style.visibility = canBack ? '' : 'hidden';
        const terminal = isTerminal(current);
        // #4: single-choice steps advance on pick, so hide OK (Typeform-style).
        // Keep it on the terminal step (explicit Submit) and on an already-
        // answered step reached via Back, so the participant can move on
        // without having to re-pick the same option.
        const hideOk = isRadioChoice(current) && !terminal && !hasSelection(current);
        okBtn.style.display = hideOk ? 'none' : '';
        okBtn.innerHTML = terminal
            ? 'Submit <i class="fa fa-paper-plane" aria-hidden="true"></i>'
            : 'OK <i class="fa fa-check" aria-hidden="true"></i>';
        hintEl.textContent = hintFor(current);
    }
    function updateProgress() {
        let total = 0, before = 0;
        pages.forEach((p, idx) => {
            const n = steps(p).length;
            if (idx < getCurrentIndex()) before += n;
            total += n;
        });
        const cur = steps(pages[getCurrentIndex()]);
        const within = Math.max(0, cur.indexOf(current));
        const globalIdx = before + within;
        const pct = total > 0 ? Math.round(((globalIdx + 1) / total) * 100) : 0;
        // Honest progress: showif can reveal/hide steps, which changes `total`
        // and would otherwise let the bar jump BACKWARD as new steps appear —
        // the misrepresentation the spec warns against. Clamp the bar so it only
        // ever moves forward. The "N of M" label still reflects the current
        // known totals (honest about what's left), but the bar never regresses.
        maxPct = Math.max(maxPct, pct);
        if (progressBar) {
            progressBar.style.width = maxPct + '%';
            progressBar.setAttribute('aria-valuenow', String(maxPct));
        }
        if (progressLabel) progressLabel.textContent = total > 0 ? (globalIdx + 1) + ' of ' + total : '';
    }

    // --- input wiring --------------------------------------------------------
    root.addEventListener('change', (e) => {
        const t = e.target;
        if (!(t instanceof Element)) return;
        if (t.name && t.name.startsWith('_item_views')) return;
        if (!current || !current.contains(t)) return;
        if (!autoAdvances(current)) return;
        if (isTerminal(current)) return;     // require an explicit Submit at the very end
        clearTimeout(advanceTimer);
        advanceTimer = setTimeout(onContinue, 450);
    });
    // --- keyboard ------------------------------------------------------------
    // True when the target is a control that consumes the keystroke itself
    // (free text, dropdowns, tom-select) — we must not hijack those.
    function isEditableTarget(t) {
        if (!t || !t.tagName) return false;
        if (t.tagName === 'TEXTAREA' || t.tagName === 'SELECT') return true;
        if (t.closest && t.closest('.ts-wrapper')) return true;
        return t.tagName === 'INPUT'
            && !['hidden', 'checkbox', 'radio', 'button', 'submit', 'file', 'reset'].includes(t.type);
    }
    // Is the page taller than the viewport right now? If so leave arrow keys to
    // scroll rather than hijacking them to navigate.
    function pageScrollable() {
        return document.documentElement.scrollHeight > window.innerHeight + 4;
    }

    // Listen on document, not root: on a statement screen focus rests on
    // <body> (outside the form), so a root-scoped handler would never see
    // Enter. Guard to our own scope so we don't hijack keys aimed at unrelated
    // page chrome (footer links, etc.).
    document.addEventListener('keydown', (e) => {
        const t = e.target;
        const inScope = t === document.body || (root.contains && root.contains(t));
        if (!inScope || !current) return;

        // Enter advances: single-line inputs, choice steps, and statement
        // screens alike. Textareas keep Enter = newline (Ctrl/Cmd+Enter
        // advances); tom-select and real buttons keep their own Enter.
        if (e.key === 'Enter') {
            if (transitioning) { e.preventDefault(); return; }
            if (t.tagName === 'TEXTAREA') {
                if (e.ctrlKey || e.metaKey) { e.preventDefault(); onContinue(true); }
                return;
            }
            if (t.closest && t.closest('.ts-wrapper')) return;
            if (t.tagName === 'BUTTON') return;   // the button activates itself
            e.preventDefault();
            onContinue(true);
            return;
        }

        // Arrow navigation when it can't be mistaken for editing or scrolling:
        // not in an editable control, not in a radio/checkbox group (arrows move
        // between options there), and not while the step overflows the viewport.
        if ((e.key === 'ArrowDown' || e.key === 'ArrowUp')
            && !e.ctrlKey && !e.metaKey && !e.altKey
            && !isEditableTarget(t)
            && !(t.tagName === 'INPUT' && (t.type === 'radio' || t.type === 'checkbox'))
            && !pageScrollable()) {
            e.preventDefault();
            if (e.key === 'ArrowDown') onContinue(true); else onBack();
        }
    });

    return { onPageShown, onContinue, onBack };
}
