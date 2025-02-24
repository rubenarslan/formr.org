// Register Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // Make sure formr configuration is available
        if (!window.formr || !window.formr.run_url) {
            console.warn('formr configuration not found or missing run_url');
            return;
        }
        
        // Get manifest path from the link element
        const manifestLink = document.querySelector('link[rel="manifest"]');
        if (!manifestLink) {
            console.warn('No manifest link found');
            return;
        }
        const manifestPath = manifestLink.href;

        // Collect all CSS and JS files from the DOM that match the current domain
        const currentOrigin = window.location.origin;
        const stylesheets = Array.from(document.styleSheets)
            .map(stylesheet => stylesheet.href)
            .filter(href => href && href.startsWith(currentOrigin));
        const scripts = Array.from(document.scripts)
            .map(script => script.src)
            .filter(src => src && src.startsWith(currentOrigin));

        const filesToCache = [...new Set([...stylesheets, ...scripts])];

        // Parse the run_url to determine the service worker path and scope
        let serviceWorkerPath = '/service-worker';
        let scope = '/';
        let runUrl = new URL(window.formr.run_url);
        let siteUrl = new URL(window.formr.site_url);
        
        console.log('run_url:', window.formr.run_url);
        // If run_url is same as site_url, we're using paths, not study specific subdomains
        if (runUrl.hostname === siteUrl.hostname) {
            // Remove trailing slash if present
            const pathWithoutTrailingSlash = runUrl.pathname.replace(/\/$/, '');
            serviceWorkerPath = `${pathWithoutTrailingSlash}/service-worker`;
            scope = pathWithoutTrailingSlash + '/';
            console.log('Using path-based service worker:', serviceWorkerPath, 'with scope:', scope);
        } else {
            console.log('Using subdomain-based service worker:', serviceWorkerPath, 'with scope:', scope);
        }
        
        // Register service worker with the correct path and scope
        navigator.serviceWorker.register(serviceWorkerPath, {
            scope: scope
        }).then(registration => {
            console.log('ServiceWorker registration successful with scope:', registration.scope);
            
            // Function to send message to active service worker
            const sendMessage = () => {
                registration.active.postMessage({
                    type: 'CACHE_ASSETS',
                    assets: filesToCache,
                    manifestPath: manifestPath
                });
            };

            // If the service worker is already active, send message immediately
            if (registration.active) {
                sendMessage();
            }

            // Listen for state changes to catch when a new service worker becomes active
            registration.addEventListener('statechange', () => {
                if (registration.active) {
                    sendMessage();
                }
            });
        }).catch(err => {
            console.log('ServiceWorker registration failed: ', err);
        });
    });
} 