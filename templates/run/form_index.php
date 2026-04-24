<?php header('Content-type: text/html; charset=utf-8'); ?><!DOCTYPE html>
<html lang="en" class="no_js">
    <head>
        <script>(function (H) { H.className = H.className.replace(/\bno_js\b/, 'js'); })(document.documentElement)</script>
        <title><?php echo $site->makeTitle(); ?></title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="<?php echo htmlspecialchars($meta['description']); ?>">
        <link rel="icon" href="<?php echo site_url('favicon.ico'); ?>">
        <script>
            window.formr = <?php echo !empty($jsConfig) ? json_encode($jsConfig) : '{}'; ?>;
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
