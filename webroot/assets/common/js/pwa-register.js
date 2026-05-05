if ('serviceWorker' in navigator && window.vapidPublicKey) {
    if (!window.formr?.run_url) {
        console.warn('formr configuration missing run_url');
    } else {
        const runUrl = new URL(window.formr.run_url);
        const siteUrl = new URL(window.formr.site_url);
        
        const isPathBased = runUrl.hostname === siteUrl.hostname;
        const serviceWorkerPath = isPathBased ? `${runUrl.pathname.replace(/\/$/, '')}/service-worker` : '/service-worker';
        const scope = isPathBased ? runUrl.pathname : '/';

        const isStandalone = window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone;

        // If the page is standalone and the _pwa parameter is not present, add it
        if (isStandalone && !window.location.search.includes('_pwa=true')) {
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.set('_pwa', 'true');
            window.history.replaceState({}, '', newUrl);
        }

        // Standalone session-recovery banner.
        //
        // A PWA shell launch should never be anonymous — every participant
        // got there via the in-browser install workflow that started from
        // a tokenized URL. If we're in standalone with no `code=` query,
        // either:
        //   (a) the server's cookie self-heal kicked in and added ?code=
        //       BEFORE this script ran (URL has ?code=, this branch is
        //       skipped), or
        //   (b) the cookie was evicted and the launch landed at the
        //       captured (token-less) start_url with no recoverable
        //       identity — the failure mode the manifest tokenization
        //       fix addresses for new installs but that *existing*
        //       installs still hit on first launch after eviction
        //       because iOS won't refetch the captured start_url.
        //
        // Show a passive banner with an inline code-paste form. Submitting
        // GETs the same URL with ?code=PASTED, loginUser() validates, and
        // the original session is back. Non-disruptive — the underlying
        // page still renders behind the banner; participants who didn't
        // lose anything can dismiss it.
        if (isStandalone && !new URLSearchParams(window.location.search).get('code')) {
            // DOMContentLoaded may have already fired by the time the
            // frontend bundle finishes evaluating pwa-register.js (the
            // bundle is loaded via <script src=…> from <head>, but
            // bundle parse + execute spans the parser's HTML-streaming
            // window). addEventListener('DOMContentLoaded') would
            // silently never fire in that case. Branch on
            // document.readyState so both timings work.
            const injectBanner = () => {
                if (document.getElementById('fmr-pwa-recovery-banner')) return;
                if (!document.body) return;
                // The pattern attribute mirrors the server's
                // user_code_regular_expression (exposed via window.formr
                // by Controller::getJsConfig). Falls back to omitting
                // pattern entirely if the server didn't expose one —
                // server-side loginUser() validation stays authoritative.
                const codePattern = window.formr.user_code_pattern || '';
                // The next block builds the banner via static-string
                // innerHTML for portability + zero deps. Do NOT
                // interpolate user input into this string; the only
                // dynamic value is the configured codePattern, which
                // originates from settings.php and is escaped here.
                const escAttr = (s) => String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
                const form = document.createElement('form');
                form.id = 'fmr-pwa-recovery-banner';
                form.method = 'get';
                form.action = window.formr.run_url;
                form.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:9999;'
                    + 'background:#fff8e1;border-bottom:1px solid #f0c419;'
                    + 'padding:.6em .8em;font:14px/1.4 -apple-system,BlinkMacSystemFont,sans-serif;'
                    + 'display:flex;flex-wrap:wrap;align-items:center;gap:.5em';
                form.innerHTML =
                    '<span style="flex:1 1 100%">'
                    + 'Lost your sign-in? Paste your participant code or open '
                    + 'the latest invitation email link.'
                    + '</span>'
                    + '<input name="code" required autocomplete="off" autocapitalize="none" '
                    +   'autocorrect="off" spellcheck="false" '
                    +   (codePattern ? `pattern="${escAttr(codePattern)}" ` : '')
                    +   'placeholder="Participant code" '
                    +   'style="flex:1;min-width:180px;padding:.4em;border:1px solid #ccc;'
                    +   'border-radius:4px;font-family:ui-monospace,Menlo,monospace">'
                    + '<input type="hidden" name="_pwa" value="true">'
                    + '<button type="submit" style="padding:.4em .9em;border:0;border-radius:4px;'
                    +   'background:#1976d2;color:#fff;cursor:pointer">Continue</button>'
                    + '<button type="button" data-dismiss="1" '
                    +   'style="padding:.4em .6em;border:0;background:transparent;cursor:pointer;'
                    +   'color:#666" aria-label="Dismiss">&times;</button>';
                form.querySelector('[data-dismiss]').addEventListener('click', () => form.remove());
                document.body.insertBefore(form, document.body.firstChild);
            };
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', injectBanner, { once: true });
            } else {
                injectBanner();
            }
        }

        // Register (idempotent) then wait for an ACTIVE worker before
        // posting CACHE_ASSETS. Two prior bugs this collapses:
        //   (1) the existingRegistration branch never sent CACHE_ASSETS,
        //       so on second+ loads the SW saw no asset list.
        //   (2) the fresh-register branch posted to registration.active
        //       which can be null mid-install; the statechange listener
        //       was the fallback but it could miss the activated edge if
        //       the SW activated between register() resolving and the
        //       listener being attached.
        // navigator.serviceWorker.ready resolves with the registration
        // ONLY when there is an active worker (state === 'activated'),
        // so awaiting it removes both races.
        (async () => {
            try {
                const existing = await navigator.serviceWorker.getRegistration(scope);
                if (!existing) {
                    const reg = await navigator.serviceWorker.register(serviceWorkerPath, { scope });
                    console.log('Service Worker registered:', reg.scope);
                } else {
                    console.log('Service Worker already registered:', existing.scope);
                }
                const ready = await navigator.serviceWorker.ready;
                if (!ready.active) return;

                const runOrigin = runUrl.origin;
                const siteOrigin = siteUrl.origin;
                const stylesheets = Array.from(document.styleSheets)
                    .map(s => s.href)
                    .filter(h => h && (h.startsWith(runOrigin) || h.startsWith(siteOrigin)));
                const scripts = Array.from(document.scripts)
                    .map(s => s.src)
                    .filter(s => s && (s.startsWith(runOrigin) || s.startsWith(siteOrigin)));
                const filesToCache = [...new Set([...stylesheets, ...scripts])];

                ready.active.postMessage({
                    type: 'CACHE_ASSETS',
                    assets: filesToCache
                });
            } catch (e) {
                console.warn('Service Worker registration / asset-cache failed:', e);
            }
        })();
    }
} else {
    console.warn('Service Worker not initialized because of missing serviceWorker or vapidPublicKey');
}