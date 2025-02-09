// Register Service Worker
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
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

        // Register service worker
        navigator.serviceWorker.register('/service-worker.js', {
            scope: '/'
        }).then(registration => {
            console.log('ServiceWorker registration successful');
            
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
            registration.addEventListener('activate', sendMessage);
        }).catch(err => {
            console.log('ServiceWorker registration failed: ', err);
        });
    });
} 