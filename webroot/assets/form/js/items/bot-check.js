// bot_check item — self-hosted, GDPR-clean "are you human" proof-of-work,
// backed by Altcha (https://altcha.org, MIT) with the memory-hard Argon2id
// algorithm.
//
// Pairs with application/Model/Item/BotCheck.php + Services/BotCheckChallenge.php.
// No third party and no CDN: the server mints a signed Altcha challenge bound to
// the participant's session, the <altcha-widget> solves it in-browser using a
// self-hosted Argon2id Web Worker (inline WASM, shipped next to this bundle),
// and the server verifies the solution at form submit. Everything stays on the
// formr server. See those files for the protocol + threat model.
//
// The challenge is fetched LAZILY from /{run}/form-bot-challenge when the widget
// mounts — not embedded at render time — so it's always fresh when the
// participant reaches the check (form_v2 renders every page into one document at
// load, which would otherwise let an embedded challenge go stale).

// Altcha is loaded as a SELF-HOSTED standalone module script, NOT webpack-
// bundled. altcha's browser build is ESM whose plugin loader uses a dynamic
// require() webpack can't resolve ("require is not defined" at bundle init), and
// webpack noParse is invalid on ESM — so we ship altcha's prebuilt dist/external
// bundle verbatim (webpack copies it to js/altcha/altcha.min.js; the template
// exposes its URL via window.formr.altchaScriptUrl) and inject it as a
// <script type="module"> on demand, only when a bot_check is present. It
// self-`customElements.define`s <altcha-widget> and sets window.$altcha.
let altchaLoading = null;
function loadAltcha() {
    if (window.$altcha) return Promise.resolve();
    if (altchaLoading) return altchaLoading;
    const url = (window.formr && window.formr.altchaScriptUrl) || '';
    altchaLoading = new Promise((resolve) => {
        if (url) {
            const s = document.createElement('script');
            s.type = 'module';
            s.src = url;
            s.onerror = () => resolve();   // network/load failure → resolve; verify just won't arm
            document.head.appendChild(s);
        }
        // the module script sets window.$altcha when it executes; poll until ready.
        const t0 = Date.now();
        const iv = setInterval(() => {
            if (window.$altcha || Date.now() - t0 > 10000) { clearInterval(iv); resolve(); }
        }, 40);
    });
    return altchaLoading;
}

let workerRegistered = false;

// Register the memory-hard Argon2id worker once. PBKDF2/SHA-* are bundled into
// the widget, but Argon2id is modular and must be provided as a Worker factory.
// We load the self-hosted copy (webpack copies it to js/altcha/argon2id.js next
// to the form bundle; the template exposes its URL via window.formr.altchaWorkerUrl).
function registerArgon2id() {
    if (workerRegistered) return;
    const g = window.$altcha;
    if (!g || !g.algorithms || typeof g.algorithms.set !== 'function') return;
    const workerUrl = (window.formr && window.formr.altchaWorkerUrl) || '';
    if (!workerUrl) return; // server fell back to SHA-256 challenges; bundled, no worker needed
    g.algorithms.set('ARGON2ID', () => new Worker(workerUrl));
    workerRegistered = true;
}

// Build the absolute challenge URL from the form's data-run-url (set by
// FormRenderer) + the item's run-relative data-challenge-path, mirroring how
// main.js derives renderPageUrl. Optional per-item difficulty rides as a query
// param (the server clamps + signs it, so it can't weaken the gate).
function challengeUrlFor(widget) {
    const form = widget.closest('form.fmr-form-v2');
    const runUrl = (form && form.getAttribute('data-run-url')) || (window.formr && window.formr.runUrl) || '';
    const path = widget.getAttribute('data-challenge-path') || 'form-bot-challenge';
    let url = (runUrl || '').replace(/\/?$/, '/') + path;
    const diff = widget.getAttribute('data-difficulty');
    if (diff) url += (url.indexOf('?') === -1 ? '?' : '&') + 'difficulty=' + encodeURIComponent(diff);
    return url;
}

export function initBotCheck(root) {
    const wrappers = root.querySelectorAll('.fmr-botcheck[data-fmr-botcheck]');
    if (!wrappers.length) return;

    wrappers.forEach((wrapper) => {
        if (wrapper.dataset.fmrBcInit === '1') return;
        wrapper.dataset.fmrBcInit = '1';

        const altcha = wrapper.querySelector('altcha-widget');
        if (!altcha) return;

        // Set the challenge URL now; the element reads it once it upgrades (when
        // the standalone script defines it). auto="off" keeps it inert until the
        // participant clicks, so there's no eager fetch on initial (multi-page)
        // render.
        const url = challengeUrlFor(wrapper);
        if (url) altcha.setAttribute('challenge', url);
    });

    // Load the standalone altcha build (registers the widget + window.$altcha),
    // then register the memory-hard Argon2id worker so the widget can solve when
    // the participant clicks. The widget upgrades in place once the script runs.
    loadAltcha().then(registerArgon2id);
}
