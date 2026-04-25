<?php header('Content-type: text/html; charset=utf-8'); ?><!DOCTYPE html>
<html lang="en" class="no_js">
    <head>
        <script>(function (H) { H.className = H.className.replace(/\bno_js\b/, 'js'); })(document.documentElement)</script>
        <title><?php echo $site->makeTitle(); ?></title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="<?php echo htmlspecialchars($meta['description']); ?>">
        <?php
        // PWA wiring (form_v2): manifest link + Apple icons + standalone
        // metas, mirroring templates/public/head.php so v2 forms with
        // configured PWA assets behave the same way as v1.
        $pwaIconBaseUrl = null;
        if ($run instanceof Run) {
            $iconPathVal = $run->getPwaIconPath();
            if ($iconPathVal) {
                $iconRoot = APPLICATION_ROOT . 'webroot/' . $iconPathVal;
                if (is_dir($iconRoot)) {
                    $pwaIconBaseUrl = asset_url(trim($iconPathVal, '/') . '/', false);
                }
            }
        }
        $hasManifest = ($run instanceof Run) && $run->getManifestJSONPath();
        $faviconUrl = ($pwaIconBaseUrl && file_exists($iconRoot . 'favicon.png'))
            ? $pwaIconBaseUrl . 'favicon.png'
            : site_url('favicon.ico');
        $vapidPublicKey = ($run instanceof Run) ? $run->getVapidPublicKey() : null;
        ?>
        <link rel="icon" href="<?php echo $faviconUrl; ?>">
        <?php if ($hasManifest): ?>
            <link rel="manifest" href="<?php echo run_url($run->name) . 'manifest'; ?>">
            <?php if ($pwaIconBaseUrl): ?>
                <link rel="apple-touch-icon" href="<?php echo $pwaIconBaseUrl . 'apple-touch-icon.png'; ?>">
                <link rel="apple-touch-icon" sizes="152x152" href="<?php echo $pwaIconBaseUrl . 'apple-touch-icon-152x152.png'; ?>">
                <link rel="apple-touch-icon" sizes="167x167" href="<?php echo $pwaIconBaseUrl . 'apple-touch-icon-167x167.png'; ?>">
                <link rel="apple-touch-icon" sizes="192x192" href="<?php echo $pwaIconBaseUrl . 'apple-touch-icon-192x192.png'; ?>">
            <?php endif; ?>
            <meta name="mobile-web-app-capable" content="yes">
            <meta name="apple-mobile-web-app-capable" content="yes">
            <meta name="application-name" content="<?php echo h($run->name); ?>">
            <meta name="apple-mobile-web-app-title" content="<?php echo h($run->title ?: $run->name); ?>">
            <meta name="theme-color" content="#2196F3">
            <meta name="msapplication-navbutton-color" content="#2196F3">
            <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
            <meta name="msapplication-starturl" content="<?php echo run_url($run->name); ?>">
        <?php endif; ?>
        <script>
            window.formr = <?php echo !empty($jsConfig) ? json_encode($jsConfig) : '{}'; ?>;
            <?php if ($vapidPublicKey): ?>
            window.vapidPublicKey = <?php echo json_encode($vapidPublicKey); ?>;
            <?php endif; ?>
        </script>
    </head>

    <body class="<?php echo isset($bodyClass) ? $bodyClass : 'fmr-run'; ?> fmr-form-v2-page" data-url="<?php echo run_url($run->name); ?>">

        <div id="fmr-page" class="fmr-form-v2-container">
            <div class="container-fluid">
                <div class="alerts-container">
                    <?php Template::loadChild('public/alerts'); ?>
                </div>

                <?php if ($run->header_image_path): ?>
                    <header class="fmr-form-v2-header">
                        <img src="<?php echo $run->header_image_path; ?>" alt="<?php echo htmlspecialchars($run->name); ?> header image">
                    </header>
                <?php endif; ?>

                <?php echo $run_content; ?>
            </div>
        </div>

        <?php
        // Prefer the webpack:watch output (dev-build/) when present so
        // developers iterating with `npm run webpack:watch` see their changes
        // without a full production rebuild. Falls back to build/ in prod.
        $formBundle = is_file(APPLICATION_ROOT . 'webroot/assets/dev-build/js/form.bundle.js')
            ? 'dev-build/js/form.bundle.js'
            : 'build/js/form.bundle.js';
        ?>
        <script src="<?php echo asset_url($formBundle); ?>"></script>
    </body>
</html>
