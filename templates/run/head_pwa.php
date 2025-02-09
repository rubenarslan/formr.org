<?php 
if ($run->getManifestJSONPath()): ?>
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

    <?php
    // Get VAPID public key from the run
    $vapidPublicKey = $run->getVapidPublicKey();
    if ($vapidPublicKey):
    ?>
    <script>
        // Make VAPID public key available globally
        window.vapidPublicKey = <?php echo json_encode($vapidPublicKey); ?>;
    </script>
    <?php endif; ?>

    <script src="<?php echo asset_url('common/js/pwa-register.js'); ?>"></script>
<?php endif; ?>

