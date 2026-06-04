// bot_check item — local-only "are you human" proof-of-work widget.
//
// Pairs with application/Model/Item/BotCheck.php + Services/BotCheckChallenge.php.
// No third party and no network call: the server mints a signed PoW challenge
// at render time, this widget solves it in-browser on a *trusted* click, and
// writes a token the server verifies. A Cloudflare-style box, but everything
// stays on the formr server (GDPR-clean). See those files for the protocol.

function leadingZeroBits(bytes) {
    let bits = 0;
    for (let i = 0; i < bytes.length; i++) {
        const b = bytes[i];
        if (b === 0) { bits += 8; continue; }
        let mask = 0x80;
        while (mask && (b & mask) === 0) { bits++; mask >>= 1; }
        break;
    }
    return bits;
}

// Find a nonce so SHA-256(salt + nonce) has >= diff leading zero bits. Digests
// run in parallel batches via SubtleCrypto so the UI never blocks; each batch
// awaits, yielding to the event loop.
async function solvePow(salt, diff) {
    if (!(window.crypto && window.crypto.subtle)) throw new Error('no-subtle');
    const enc = new TextEncoder();
    const BATCH = 512;
    for (let base = 0; base < 8000000; base += BATCH) {
        const jobs = [];
        for (let i = 0; i < BATCH; i++) {
            const n = base + i;
            jobs.push(crypto.subtle.digest('SHA-256', enc.encode(salt + n)).then((buf) => [n, new Uint8Array(buf)]));
        }
        const results = await Promise.all(jobs);
        for (const [n, bytes] of results) {
            if (leadingZeroBits(bytes) >= diff) return String(n);
        }
    }
    throw new Error('pow-giveup');
}

export function initBotCheck(root) {
    root.querySelectorAll('.fmr-botcheck[data-fmr-botcheck]').forEach((widget) => {
        if (widget.dataset.fmrBcInit === '1') return;
        widget.dataset.fmrBcInit = '1';

        const hidden = widget.querySelector('input[type=hidden]');
        const box = widget.querySelector('.fmr-botcheck-box');
        const status = widget.querySelector('.fmr-botcheck-status');
        if (!hidden || !box) return;

        const iat = widget.dataset.iat;
        const salt = widget.dataset.salt;
        const diff = parseInt(widget.dataset.diff || '0', 10);
        const sig = widget.dataset.sig;
        // No server challenge (server couldn't sign) → plain confirm box; the
        // server's verify() fails open in that misconfiguration.
        const hasChallenge = !!(iat && salt && diff && sig);

        let solving = false;
        let solved = false;
        const setState = (s, msg) => {
            widget.dataset.state = s;
            box.setAttribute('aria-checked', s === 'verified' ? 'true' : 'false');
            if (status) status.textContent = msg || '';
        };
        setState('idle', '');

        const finish = () => {
            solved = true;
            solving = false;
            setState('verified', 'Verified');
            hidden.dispatchEvent(new Event('input', { bubbles: true }));
            hidden.dispatchEvent(new Event('change', { bubbles: true }));
        };

        const start = async (ev) => {
            // Require a genuine (trusted) interaction — a page-script .click()
            // carries isTrusted=false and is ignored, so JS automation that
            // never really interacts can't trip the solve.
            if (ev && ev.isTrusted === false) return;
            if (solving || solved) return;
            solving = true;
            const t0 = performance.now();
            setState('verifying', 'Verifying…');

            if (!hasChallenge) { hidden.value = 'ok'; finish(); return; }
            try {
                const nonce = await solvePow(salt, diff);
                hidden.value = JSON.stringify({
                    iat: Number(iat), salt, diff, sig, nonce,
                    el: Math.round(performance.now() - t0),
                });
                finish();
            } catch (e) {
                solving = false;
                setState('error', 'Could not verify — tap to try again');
            }
        };

        box.addEventListener('click', start);
        box.addEventListener('keydown', (e) => {
            if (e.key === ' ' || e.key === 'Enter' || e.key === 'Spacebar') { e.preventDefault(); start(e); }
        });
    });
}
