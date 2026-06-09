// RequestPhone item — "continue on your phone" hand-off. Port of v1's
// initializeRequestPhone (PWAInstaller.js) into the v2 form bundle.
//
// Mechanism (same as v1, no server endpoint): the desktop screen shows a QR /
// link encoding the participant's resumable run URL (run_url + ?code=). When the
// participant opens it ON THEIR PHONE, the SAME run session resumes there and the
// server's mobile UA-sniff (RequestPhone_Item::setMoreOptions) auto-answers the
// item (`is_phone`, no_user_input_required). So the desktop never needs to detect
// the scan — it just has to get the participant to their phone.
//
// Required policy: a REQUIRED request_phone hard-gates on desktop (the participant
// must move to their phone — we keep the validity set so the desktop submit is
// blocked). An OPTIONAL one lets the desktop user proceed and records `is_desktop`.
// Values use the PHP allowlist (RequestPhone_Item::validateInput): is_phone /
// is_desktop / qr_scanned / not_checked.

import QRCodeStyling from 'qr-code-styling';

const MOBILE_UA_RE = /Mobi|Android|iPhone|iPad|iPod|BlackBerry|webOS|IEMobile|Opera Mini/i;

// Resumable run URL (study origin + ?code=), injected by templates/run/form_index.php.
// Fall back to the current location so a missing global still produces a usable QR.
function resumeUrl() {
    if (window.formr && window.formr.runResumeUrl) return window.formr.runResumeUrl;
    return window.location.href;
}

async function logoUrlFromManifest() {
    try {
        const link = document.querySelector('link[rel="manifest"]');
        if (!link) return null;
        const res = await fetch(link.href);
        if (!res.ok) return null;
        const m = await res.json();
        if (Array.isArray(m.icons) && m.icons.length) {
            const big = m.icons.find((ic) => ic.sizes && (ic.sizes.includes('192x192') || ic.sizes.includes('512x512')));
            return (big && big.src) || (m.icons[0] && m.icons[0].src) || null;
        }
    } catch (e) { /* no logo */ }
    return null;
}

function renderQR(container, url, logoUrl) {
    const qr = new QRCodeStyling({
        width: 240, height: 240, type: 'svg', data: url,
        dotsOptions: { color: '#000000', type: 'rounded' },
        backgroundOptions: { color: '#ffffff' },
        cornersSquareOptions: { color: '#2196F3', type: 'extra-rounded' },
        cornersDotOptions: { color: '#2196F3', type: 'dot' },
        imageOptions: { crossOrigin: 'anonymous', margin: 8, imageSize: 0.4 },
    });
    if (logoUrl) qr.update({ image: logoUrl });
    container.innerHTML = '';
    qr.append(container);
}

// Build a "copy link" affordance once (the PHP renders only the QR container).
function ensureCopyControl(wrapper, url) {
    if (wrapper.querySelector('.request-phone-copy')) return;
    const row = document.createElement('div');
    row.className = 'request-phone-copy';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-outline-secondary btn-sm';
    btn.innerHTML = '<i class="fa fa-copy"></i> Copy link to phone';
    btn.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(url);
            btn.innerHTML = '<i class="fa fa-check"></i> Copied';
            setTimeout(() => { btn.innerHTML = '<i class="fa fa-copy"></i> Copy link to phone'; }, 2000);
        } catch (e) {
            // Fallback: reveal the link so the participant can copy it
            // manually (once — repeated failures must not stack links).
            if (!row.querySelector('.request-phone-link')) {
                const a = document.createElement('a');
                a.href = url; a.textContent = url; a.className = 'request-phone-link';
                row.appendChild(a);
            }
        }
    });
    row.appendChild(btn);
    const status = wrapper.querySelector('.status-message');
    (status || wrapper).insertAdjacentElement(status ? 'beforebegin' : 'beforeend', row);
}

export function initRequestPhone(root) {
    const isMobile = MOBILE_UA_RE.test(navigator.userAgent);
    root.querySelectorAll('.request-phone-wrapper').forEach((wrapper) => {
        const hidden = wrapper.querySelector('input');
        const status = wrapper.querySelector('.status-message');
        const group = wrapper.closest('.form-group');
        const required = group ? group.classList.contains('required') : false;

        if (isMobile) {
            // Already on a phone — the server marks it answered; mirror that client-side.
            if (hidden && !hidden.value) hidden.value = 'is_phone';
            if (hidden) hidden.setCustomValidity('');
            if (status) status.textContent = 'You are already on a mobile device. You can continue.';
            if (group) group.classList.add('formr_answered');
            return;
        }

        // Desktop — render the QR + copy link so they can continue on their phone.
        const url = resumeUrl();
        const qrContainer = wrapper.querySelector('.qr-code-container');
        if (qrContainer) {
            qrContainer.style.display = '';
            logoUrlFromManifest().then((logo) => { try { renderQR(qrContainer, url, logo); } catch (e) { /* QR optional */ } });
        }
        ensureCopyControl(wrapper, url);

        if (required) {
            // Hard gate: desktop must move to the phone (where the resumed session
            // auto-answers is_phone). Keep the submit blocked on desktop.
            if (hidden) hidden.setCustomValidity('Please continue this study on your phone — scan the code above.');
            if (status) status.textContent = 'Scan this QR code with your phone to continue the study there.';
        } else {
            // Optional: let the desktop user proceed; record is_desktop.
            if (hidden) {
                if (!hidden.value || hidden.value === 'not_requested' || hidden.value === 'not_checked') hidden.value = 'is_desktop';
                hidden.setCustomValidity('');
            }
            if (group) group.classList.add('formr_answered');
            if (status) status.textContent = 'Scan this QR code to continue on your phone, or continue here.';
        }
    });
}
