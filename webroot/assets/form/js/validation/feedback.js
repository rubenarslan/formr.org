// Native Constraint Validation feedback rendering for v2.
//
// v1 used webshim to surface consistent inline messages. v2 has neither
// webshim nor jQuery; we read `validity` from each `<input>`/`<select>`/
// `<textarea>` and render `.fmr-invalid-feedback` / `.fmr-btn-feedback` next
// to the offender. Native browser tooltips don't render reliably on iOS
// Safari (clipped, hidden behind sticky headers, sometimes skipped); inline
// feedback is the fallback that actually appears.
//
// applyErrors() pushes a server response error map onto the same surface so
// `.fmr-invalid-feedback` is the single rendering path.

// Clear ONLY the customValidity values that `applyErrors` wrote (server-
// side validation responses). Init-armed customValidity from gating items
// (AddToHomeScreen, PushNotification, RequestCookie, …) must persist —
// otherwise the participant can advance past a required gate without
// completing it. Server-set inputs are tagged with `data-fmr-server-validity`.
export function clearCustomValidity(pageEl) {
    pageEl.querySelectorAll(
        'input[data-fmr-server-validity], select[data-fmr-server-validity], textarea[data-fmr-server-validity]'
    ).forEach((inp) => {
        inp.setCustomValidity('');
        delete inp.dataset.fmrServerValidity;
    });
}

export function findErrorTarget(pageEl, name) {
    let el = pageEl.querySelector(`[name="${CSS.escape(name)}"]`);
    if (!el) el = pageEl.querySelector(`[name="${CSS.escape(name + '[]')}"]`);
    return el;
}

// Apply a server `errors` map to inputs. Items the server can't pin to a
// specific input land in a top banner; everything else gets inline.
export function applyErrors(pageEl, errors) {
    pageEl.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
    pageEl.querySelectorAll('.fmr-invalid-feedback').forEach((el) => el.remove());

    const unplaced = [];
    Object.entries(errors).forEach(([name, msg]) => {
        const el = findErrorTarget(pageEl, name);
        if (!el) {
            unplaced.push({ name, msg: String(msg) });
            return;
        }
        el.setCustomValidity(String(msg));
        el.dataset.fmrServerValidity = '1';
        el.classList.add('is-invalid');
        const feedback = document.createElement('div');
        feedback.className = 'invalid-feedback fmr-invalid-feedback d-block';
        feedback.textContent = String(msg);
        const anchor = el.closest('.controls, .form-group') || el.parentElement;
        if (anchor && anchor.parentElement) {
            anchor.parentElement.insertBefore(feedback, anchor.nextSibling);
        } else {
            el.insertAdjacentElement('afterend', feedback);
        }
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
}

// Has a required group been "answered"? Many item types own their value in an
// input native Constraint Validation can't see — a hidden carrier (bot_check
// token, VAS slider value, geopoint coords, audio/video/file recordings) or a
// readonly field (geopoint) — so checkValidity never flags a required-but-empty
// one. This generic check covers them uniformly: a real answer is a checked
// option, a chosen file, a non-empty visible (non-readonly) named field, or a
// non-empty value-carrier hidden input.
function isAnswered(g) {
    if (g.querySelector('input[type=radio]:checked, input[type=checkbox]:checked')) return true;
    for (const f of g.querySelectorAll('input[type=file]')) {
        if (f.files && f.files.length) return true;
    }
    const named = g.querySelectorAll(
        'input[name]:not([type=hidden]):not([type=radio]):not([type=checkbox]):not([type=file]), textarea[name], select[name]',
    );
    for (const el of named) {
        if (el.readOnly || el.disabled) continue;       // readonly geopoint field doesn't count
        if (String(el.value == null ? '' : el.value).trim() !== '') return true;
    }
    for (const h of g.querySelectorAll('input[type=hidden]')) {
        if (h.name && h.name.startsWith('_item_views')) continue; // bookkeeping, not an answer
        if (String(h.value == null ? '' : h.value).trim() !== '') return true;
    }
    return false;
}

// Friendly per-type message for a required-but-unanswered group.
function requiredMsg(g) {
    if (g.classList.contains('item-bot_check')) return 'Please verify that you are human to continue.';
    if (g.classList.contains('item-visual_analog_scale')) return 'Please choose a point on the scale to continue.';
    if (g.classList.contains('item-geopoint')) return 'Please provide your location to continue.';
    if (g.matches('.item-file, .item-image, .item-audio, .item-video')) return 'Please add a file to continue.';
    return "We'll need this to continue.";
}

// Returns true if the page is valid and submit may proceed; false if we
// surfaced any inline errors (caller should bail).
export function validatePageAndShowFeedback(pageEl) {
    pageEl.querySelectorAll('.fmr-invalid-feedback').forEach((el) => el.remove());
    // Reset stale validation state on BOTH descendants AND pageEl itself. In the
    // solo layout `validate(current)` passes a single `.form-group` as pageEl, so
    // any `is-invalid` / `fmr-has-client-error` (a within-pass dedup flag) that
    // landed on that form-group survives `querySelectorAll` (descendants only)
    // and, on the next attempt, makes the offenders loop `return` early before
    // rendering feedback — i.e. an invalid required field silently blocks with no
    // message on the 2nd OK. Clearing pageEl too closes that leak.
    pageEl.classList.remove('is-invalid', 'fmr-has-client-error');
    pageEl.querySelectorAll('.is-invalid, .fmr-has-client-error')
        .forEach((el) => el.classList.remove('is-invalid', 'fmr-has-client-error'));

    const fields = Array.from(pageEl.querySelectorAll('input[name], select[name], textarea[name]'))
        .filter((el) => !el.disabled && !el.name.startsWith('_item_views'));

    const offenders = [];
    fields.forEach((el) => {
        if (!el.willValidate) return;
        if (el.checkValidity()) return;
        offenders.push(el);
    });

    // Generic required-but-unanswered gate. Catches every item whose required-ness
    // native validation can't see (bot_check, visual_analog_scale, geopoint,
    // file/image/audio/video, …) so a required-but-empty one is blocked client-side
    // with a message instead of silently bouncing off the server. Scope: the page
    // section in default layout, or the single seated group in solo (where
    // `validate(current)` passes one .form-group). Skip groups already flagged by
    // native validation above (no double message) and showif-hidden ones.
    const nativeGroups = new Set(offenders.map((el) => el.closest('.form-group')).filter(Boolean));
    const scope = (pageEl.matches && pageEl.matches('.form-group'))
        ? [pageEl]
        : Array.from(pageEl.querySelectorAll('.form-group'));
    const unanswered = scope.filter((g) => g.classList.contains('required')
        && !g.classList.contains('hidden') && g.offsetParent !== null
        // A submit item carries `required` (it's not "optional") but is a button,
        // not an answerable field — it can never be "answered", so excluding it
        // here mirrors the server's required check (UnitSession excludes 'submit')
        // and stops the page's own big submit button from blocking itself.
        && !g.classList.contains('item-submit')
        && !nativeGroups.has(g) && !isAnswered(g));

    if (offenders.length === 0 && unanswered.length === 0) return true;

    let firstFocusTarget = null;
    offenders.forEach((el) => {
        const wrapper = el.closest('.form-group') || el.parentElement;
        if (wrapper && wrapper.classList.contains('fmr-has-client-error')) return;
        if (wrapper) wrapper.classList.add('fmr-has-client-error');

        // A `block` item is a display-only guard (e.g. "you can't spend > $100")
        // enforced by a hidden required checkbox that can't be ticked. Its own
        // red message IS the explanation — don't append a misleading "please
        // check this box". Still counts as an offender, so the submit is blocked.
        if (wrapper && wrapper.classList.contains('item-block')) {
            wrapper.classList.add('is-invalid');
            if (!firstFocusTarget) firstFocusTarget = wrapper;
            return;
        }

        // A `bot_check` is gated by Altcha's own required checkbox, whose native
        // message ("Please check this box…") is generic and doesn't match the
        // server's BotCheckChallenge::verify message. Show the domain message so
        // client and server agree, regardless of the browser's wording.
        if (wrapper && wrapper.classList.contains('item-bot_check')) {
            el.classList.add('is-invalid');
            wrapper.classList.add('is-invalid');
            if (!wrapper.querySelector('.fmr-invalid-feedback, .fmr-btn-feedback')) {
                const fb = document.createElement('div');
                fb.className = 'invalid-feedback fmr-invalid-feedback d-block fmr-botcheck-feedback';
                fb.textContent = requiredMsg(wrapper);
                const anchor = wrapper.querySelector('.fmr-botcheck') || el.closest('.controls') || el;
                anchor.insertAdjacentElement('afterend', fb);
            }
            if (!firstFocusTarget) firstFocusTarget = wrapper.querySelector('.fmr-botcheck-box, input[type=checkbox]') || el;
            return;
        }

        const btnGroup = wrapper && wrapper.querySelector('.btn-group');
        const isHiddenInput = el.type === 'hidden'
            || el.style.display === 'none'
            || (el.offsetParent === null && el.type !== 'radio' && el.type !== 'checkbox');

        if (btnGroup && (isHiddenInput || el.type === 'radio' || el.type === 'checkbox')) {
            if (!wrapper.querySelector('.fmr-btn-feedback')) {
                const fb = document.createElement('div');
                fb.className = 'invalid-feedback fmr-btn-feedback d-block';
                // Prefer the browser's contextual message (e.g. for malformed
                // input formats); fall back to friendlier copy.
                fb.textContent = el.validationMessage || 'Please pick an option to continue.';
                btnGroup.insertAdjacentElement('afterend', fb);
            }
            wrapper.classList.add('is-invalid');
            if (!firstFocusTarget) firstFocusTarget = btnGroup.querySelector('.btn') || btnGroup;
            return;
        }

        el.classList.add('is-invalid');
        const fb = document.createElement('div');
        fb.className = 'invalid-feedback fmr-invalid-feedback d-block';
        fb.textContent = el.validationMessage || "We'll need this to continue.";
        const anchor = el.closest('.controls, .form-group') || el.parentElement;
        if (anchor && anchor.parentElement) {
            anchor.parentElement.insertBefore(fb, anchor.nextSibling);
        } else {
            el.insertAdjacentElement('afterend', fb);
        }
        if (!firstFocusTarget) firstFocusTarget = el;
    });

    pageEl.querySelectorAll('.fmr-has-client-error').forEach((el) => el.classList.remove('fmr-has-client-error'));

    unanswered.forEach((g) => {
        if (g.classList.contains('is-invalid')) return;
        g.classList.add('is-invalid');
        if (!g.querySelector('.fmr-invalid-feedback, .fmr-btn-feedback')) {
            // Keep the type-specific feedback classes some tests/styles key on.
            const extra = g.classList.contains('item-visual_analog_scale') ? ' fmr-vas-feedback'
                : g.classList.contains('item-bot_check') ? ' fmr-botcheck-feedback' : '';
            const fb = document.createElement('div');
            fb.className = 'invalid-feedback fmr-invalid-feedback d-block' + extra;
            fb.textContent = requiredMsg(g);
            // Anchor the message just after the widget/control area.
            const anchor = g.querySelector('.vas-controls, .fmr-botcheck, .controls-inner, .controls') || g;
            anchor.insertAdjacentElement('afterend', fb);
        }
        if (!firstFocusTarget) {
            firstFocusTarget = g.querySelector(
                'input:not([type=hidden]):not([disabled]), textarea, select, .fmr-botcheck-box, .vas-display, .btn[data-for]',
            ) || g;
        }
    });

    if (firstFocusTarget) {
        try { firstFocusTarget.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch {}
        try { firstFocusTarget.focus({ preventScroll: true }); } catch {}
    }
    return false;
}

// Inline validation on blur (focusout). Surfaces FORMAT errors (malformed
// email, out-of-range number/date) the moment a filled field loses focus, per
// the spec's "validate on blur, not on every keystroke." Deliberately does NOT
// flag empty required fields on blur — nagging about every field the
// participant tabs past is a breakoff driver; required-ness is caught at
// submit. One delegated focusout listener (blur doesn't bubble; focusout does).
export function installBlurValidation(root) {
    root.addEventListener('focusout', (e) => {
        const el = e.target;
        if (!el || !el.matches || !el.matches('input, select, textarea')) return;
        if (el.disabled || !el.willValidate) return;
        if (el.name && el.name.startsWith('_item_views')) return;
        // Skip button-group radios/checkboxes — blur on those is noisy and they
        // surface via .fmr-btn-feedback at submit instead.
        if (el.type === 'radio' || el.type === 'checkbox') return;

        const wrapper = el.closest('.form-group');
        const clear = () => {
            el.classList.remove('is-invalid');
            if (wrapper) wrapper.querySelectorAll('.fmr-invalid-feedback').forEach((x) => x.remove());
        };
        // Empty → not our job on blur (required handled at submit).
        if (el.value == null || el.value === '') { clear(); return; }
        if (el.checkValidity()) { clear(); return; }

        // Invalid + non-empty → show the inline message next to the field.
        if (wrapper && wrapper.querySelector('.fmr-invalid-feedback')) return; // already shown
        el.classList.add('is-invalid');
        const fb = document.createElement('div');
        fb.className = 'invalid-feedback fmr-invalid-feedback d-block';
        fb.textContent = el.validationMessage || 'Please check this entry.';
        const anchor = el.closest('.controls, .form-group') || el.parentElement;
        if (anchor && anchor.parentElement) {
            anchor.parentElement.insertBefore(fb, anchor.nextSibling);
        } else {
            el.insertAdjacentElement('afterend', fb);
        }
    });
}

// Clear inline feedback on any input change so participants don't see stale
// "Please fill in this field" after they type. One delegated listener.
export function installFeedbackClearer(root) {
    root.addEventListener('input', (e) => {
        const wrapper = e.target.closest('.form-group');
        if (!wrapper) return;
        wrapper.querySelectorAll('.fmr-invalid-feedback, .fmr-btn-feedback').forEach((el) => el.remove());
        wrapper.classList.remove('is-invalid', 'fmr-has-client-error');
        wrapper.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        if (e.target.dataset?.fmrServerValidity) {
            try { e.target.setCustomValidity(''); } catch {}
            delete e.target.dataset.fmrServerValidity;
        }
    });
}
