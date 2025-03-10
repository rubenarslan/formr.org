<?php 
if ($run->getManifestJSONPath()): ?>
    <link rel="manifest" href="<?php echo run_url($run->name).'manifest'; ?>">
    
    <!-- Safari specific icons -->
    <link rel="apple-touch-icon" href="<?php echo asset_url('pwa/maskable_icon_x192.png'); ?>" sizes="192x192">
    <link rel="apple-touch-icon" href="<?php echo asset_url('pwa/maskable_icon_x512.png'); ?>" sizes="512x512">
    <link rel="apple-touch-icon" href="<?php echo asset_url('pwa/maskable_icon_x384.png'); ?>" sizes="384x384">
    <link rel="apple-touch-icon" href="<?php echo asset_url('pwa/maskable_icon_x128.png'); ?>" sizes="128x128">
    
    <!-- Safari specific meta tags -->
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="application-name" content="<?php echo h($run->name); ?>">
    <meta name="apple-mobile-web-app-title" content="<?php echo h($run->title ?: $run->name); ?>">
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
    
<?php endif; ?>

