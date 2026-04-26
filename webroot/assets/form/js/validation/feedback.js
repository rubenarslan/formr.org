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

// Returns true if the page is valid and submit may proceed; false if we
// surfaced any inline errors (caller should bail).
export function validatePageAndShowFeedback(pageEl) {
    pageEl.querySelectorAll('.fmr-invalid-feedback').forEach((el) => el.remove());
    pageEl.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));

    const fields = Array.from(pageEl.querySelectorAll('input[name], select[name], textarea[name]'))
        .filter((el) => !el.disabled && !el.name.startsWith('_item_views'));

    const offenders = [];
    fields.forEach((el) => {
        if (!el.willValidate) return;
        if (el.checkValidity()) return;
        offenders.push(el);
    });

    if (offenders.length === 0) return true;

    let firstFocusTarget = null;
    offenders.forEach((el) => {
        const wrapper = el.closest('.form-group') || el.parentElement;
        if (wrapper && wrapper.classList.contains('fmr-has-client-error')) return;
        if (wrapper) wrapper.classList.add('fmr-has-client-error');

        const btnGroup = wrapper && wrapper.querySelector('.btn-group');
        const isHiddenInput = el.type === 'hidden'
            || el.style.display === 'none'
            || (el.offsetParent === null && el.type !== 'radio' && el.type !== 'checkbox');

        if (btnGroup && (isHiddenInput || el.type === 'radio' || el.type === 'checkbox')) {
            if (!wrapper.querySelector('.fmr-btn-feedback')) {
                const fb = document.createElement('div');
                fb.className = 'invalid-feedback fmr-btn-feedback d-block';
                fb.textContent = el.validationMessage || 'Please choose an option.';
                btnGroup.insertAdjacentElement('afterend', fb);
            }
            wrapper.classList.add('is-invalid');
            if (!firstFocusTarget) firstFocusTarget = btnGroup.querySelector('.btn') || btnGroup;
            return;
        }

        el.classList.add('is-invalid');
        const fb = document.createElement('div');
        fb.className = 'invalid-feedback fmr-invalid-feedback d-block';
        fb.textContent = el.validationMessage || 'Please fill in this field.';
        const anchor = el.closest('.controls, .form-group') || el.parentElement;
        if (anchor && anchor.parentElement) {
            anchor.parentElement.insertBefore(fb, anchor.nextSibling);
        } else {
            el.insertAdjacentElement('afterend', fb);
        }
        if (!firstFocusTarget) firstFocusTarget = el;
    });

    pageEl.querySelectorAll('.fmr-has-client-error').forEach((el) => el.classList.remove('fmr-has-client-error'));

    if (firstFocusTarget) {
        try { firstFocusTarget.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch {}
        try { firstFocusTarget.focus({ preventScroll: true }); } catch {}
    }
    return false;
}

// Clear inline feedback on any input change so participants don't see stale
// "Please fill in this field" after they type. One delegated listener.
export function installFeedbackClearer(root) {
    root.addEventListener('input', (e) => {
        const wrapper = e.target.closest('.form-group');
        if (!wrapper) return;
        wrapper.querySelectorAll('.fmr-invalid-feedback, .fmr-btn-feedback').forEach((el) => el.remove());
        wrapper.classList.remove('is-invalid');
        wrapper.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
        if (e.target.dataset?.fmrServerValidity) {
            try { e.target.setCustomValidity(''); } catch {}
            delete e.target.dataset.fmrServerValidity;
        }
    });
}
