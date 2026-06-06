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

// Side-effect import: registers the <altcha-widget> custom element and creates
// the global window.$altcha (AltchaGlobal: { algorithms, defaults, i18n, ... }).
// Use the `external` (browser-standalone) build, NOT the package main entry —
// altcha's main ESM build references `require` (Node/SSR-oriented) and throws
// "require is not defined" once webpack bundles it for the browser. The external
// build self-`customElements.define`s the widget and sets window.$altcha, with
// no require().
import 'altcha/external';

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

    registerArgon2id();

    wrappers.forEach((wrapper) => {
        if (wrapper.dataset.fmrBcInit === '1') return;
        wrapper.dataset.fmrBcInit = '1';

        const altcha = wrapper.querySelector('altcha-widget');
        if (!altcha) return;

        // Set the challenge URL last so the element doesn't fetch before the
        // worker is registered. auto="off" already keeps it inert until the
        // participant clicks the checkbox, but setting challenge here also
        // guarantees no eager network call on initial (multi-page) render.
        const url = challengeUrlFor(wrapper);
        if (url) altcha.setAttribute('challenge', url);
    });
}
