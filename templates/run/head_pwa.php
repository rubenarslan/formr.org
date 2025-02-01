<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="application-name" content="<?php echo $site->makeTitle(); ?>">
<meta name="apple-mobile-web-app-title" content="<?php echo $site->makeTitle(); ?>">
<meta name="theme-color" content="#2196F3">
<meta name="msapplication-navbutton-color" content="#2196F3">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="msapplication-starturl" content="/">
<link rel="manifest" href="<?php echo asset_url('pwa/manifest.json'); ?>">
<link rel="apple-touch-icon" href="<?php echo asset_url('pwa/maskable_icon_x192.png'); ?>">

<script>
    // Register Service Worker
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/assets/pwa/service-worker.js')
                .then(registration => {
                    console.log('ServiceWorker registration successful');
                })
                .catch(err => {
                    console.log('ServiceWorker registration failed: ', err);
                });
        });
    }
</script>