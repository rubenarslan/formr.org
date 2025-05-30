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

        navigator.serviceWorker.getRegistration(scope).then(existingRegistration => {
            if (!existingRegistration) {
                navigator.serviceWorker.register(serviceWorkerPath, { scope }).then(registration => {
                    console.log('Service Worker registered:', registration.scope);
                    // Collect all CSS and JS files from the DOM that match the current domain
                    const runOrigin = runUrl.origin;
                    const siteOrigin = siteUrl.origin;
                    const stylesheets = Array.from(document.styleSheets)
                        .map(stylesheet => stylesheet.href)
                        .filter(href => href && (href.startsWith(runOrigin) || href.startsWith(siteOrigin)));
                    const scripts = Array.from(document.scripts)
                        .map(script => script.src)
                        .filter(src => src && (src.startsWith(runOrigin) || src.startsWith(siteOrigin)));

                    const filesToCache = [...new Set([...stylesheets, ...scripts])];

                    // Function to send assets to cache to service worker
                    const sendAssetsToCache = () => {
                        console.log('Sending assets to cache to service worker');
                        registration.active.postMessage({
                            type: 'CACHE_ASSETS',
                            assets: filesToCache
                        });
                    };

                    // If the service worker is already active, send messages immediately
                    if (registration.active) {
                        sendAssetsToCache();
                    }

                    // Listen for state changes to catch when a new service worker becomes active
                    const sw = registration.waiting || registration.installing;
                    if(sw) {
                        sw.addEventListener('statechange', (e) => {
                            if (e.target.state === 'activated') {
                                sendAssetsToCache();
                            }
                        });
                    }
                });
            } else {
                console.log('Service Worker already registered:', existingRegistration?.scope);
            }
        });
    }
}