<?php if ($run->getManifestJSONPath()): ?>
    <link rel="manifest" href="<?php echo $run->getManifestJSONPath(); ?>">
    <link rel="apple-touch-icon" href="<?php echo asset_url('pwa/maskable_icon_x192.png'); ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="application-name" content="<?php echo $run->name; ?>">
    <meta name="apple-mobile-web-app-title" content="<?php echo $run->title; ?>">
    <meta name="theme-color" content="#2196F3">
    <meta name="msapplication-navbutton-color" content="#2196F3">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="msapplication-starturl" content="<?php echo run_url($run->name); ?>">

    <script>
    // Register Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            // Collect all CSS and JS files from the DOM
            const stylesheets = Array.from(document.styleSheets)
                .map(stylesheet => stylesheet.href)
                .filter(href => href !== null);
            const scripts = Array.from(document.scripts)
                .map(script => script.src)
                .filter(src => src !== '');

            const filesToCache = [...new Set([...stylesheets, ...scripts])];

            // Register service worker
            navigator.serviceWorker.register('/assets/pwa/service-worker.js')
                .then(registration => {
                    console.log('ServiceWorker registration successful');
                    // Send the files to cache to the service worker
                    if (registration.active) {
                        registration.active.postMessage({
                            type: 'CACHE_ASSETS',
                            assets: filesToCache
                        });
                    }
                })
                .catch(err => {
                    console.log('ServiceWorker registration failed: ', err);
                });
        });
    }
    </script>
<?php endif; ?>

