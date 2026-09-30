// PWA items of the form_v2 bundle: the service-worker registration and the
// add_to_home_screen / push_notification items (the v2 port of v1's
// pwa-register.js + PWAInstaller.js, without jQuery).
//
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

import { t, escapeHtml, iconLabel } from '../lib/i18n.js';
import { setProgrammaticValue, disableOwnControl } from '../lib/inputs.js';

/**
 * @param {{root: HTMLFormElement, runUrl: string}} ctx the form and its run URL
 */
export function initPwaItems({ root, runUrl }) {
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

}
