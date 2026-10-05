<?php
header('Content-type: text/html; charset=utf-8');
// The v2 page state from Form::getUnitSessionOutput ($form_v2): the
// study's participant language (survey_studies.language, patch 114) drives
// the document language and text direction, and its string table is
// injected as window.fmrStrings below.
$formV2 = !empty($form_v2) && is_array($form_v2) ? $form_v2 : array();
$formLanguage = !empty($formV2['language']) ? (string) $formV2['language'] : 'en';
$formDir = FormStrings::dir($formLanguage);
?><!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($formLanguage, ENT_QUOTES); ?>" dir="<?php echo $formDir; ?>" class="no_js">
    <head>
        <script>(function (H) { H.className = H.className.replace(/\bno_js\b/, 'js'); })(document.documentElement)</script>
        <title><?php echo $site->makeTitle(); ?></title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="<?php echo htmlspecialchars($meta['description']); ?>">
        <?php Template::loadChild('public/pwa_head', array('run' => $run)); ?>
        <?php $vapidPublicKey = ($run instanceof Run) ? $run->getVapidPublicKey() : null; ?>
        <?php
        // Form bundle paths. Prefer webroot/assets/dev-build/ when it has
        // BOTH the JS and the extracted CSS — webpack's
        // MiniCssExtractPlugin emits both. Falling back to build/ if the
        // dev-build is partial (older watch from before the CSS-extract
        // change, or watch hasn't run yet). Bundling the dev/prod choice
        // here ensures the <link rel=stylesheet> always points at the
        // same build as the <script>.
        $formBundleDir = (
            is_file(APPLICATION_ROOT . 'webroot/assets/dev-build/js/form.bundle.js') &&
            is_file(APPLICATION_ROOT . 'webroot/assets/dev-build/css/form.bundle.css')
        ) ? 'dev-build' : 'build';
        $formBundleCss = $formBundleDir . '/css/form.bundle.css';
        if (is_file(APPLICATION_ROOT . 'webroot/assets/' . $formBundleCss)):
        ?>
            <link rel="stylesheet" href="<?php echo asset_url($formBundleCss); ?>">
        <?php endif; ?>
        <?php
        // The run's own CSS and JS (its settings), as on every other page of
        // the run: RunController::filterAssets registers them under the run's
        // name. The other groups are the v1 bundle's, not this page's. After
        // the form's, so the run's styles override them.
        $runAssets = isset($run) && $run instanceof Run ? (string) $run->name : '';
        if ($runAssets !== '' && !empty($css[$runAssets])) {
            print_stylesheets($css[$runAssets], $runAssets);
        }
        ?>
        <script>
            window.formr = <?php echo !empty($jsConfig) ? json_encode($jsConfig) : '{}'; ?>;
            // Engine-chrome strings in the study's language (FormStrings;
            // English fallback key-by-key). Read by the bundle's lib/i18n.js.
            window.fmrStrings = <?php echo json_encode(!empty($formV2['strings']) && is_array($formV2['strings']) ? $formV2['strings'] : FormStrings::table($formLanguage), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
            // bot_check (Altcha): same-origin URL of the self-hosted memory-hard
            // Argon2id Web Worker, copied next to the form bundle by webpack.
            // Resolved against the active build/dev-build dir so it always matches
            // the loaded bundle. No third-party / CDN — fully self-hosted.
            window.formr.altchaWorkerUrl = <?php echo json_encode(asset_url($formBundleDir . '/js/altcha/argon2id.js')); ?>;
            // bot_check (Altcha): same-origin URL of the self-hosted altcha widget
            // standalone build, injected as a <script type=module> by bot-check.js
            // (not bundled — see webpack.config.js / bot-check.js).
            window.formr.altchaScriptUrl = <?php echo json_encode(asset_url($formBundleDir . '/js/altcha/altcha.min.js')); ?>;
            <?php if ($vapidPublicKey): ?>
            window.vapidPublicKey = <?php echo json_encode($vapidPublicKey); ?>;
            <?php endif; ?>
            <?php
            // Resumable participant URL (run URL + ?code=) for the request_phone
            // item's QR / hand-off link: lets the participant continue the SAME
            // session on their phone, where the server's mobile UA-sniff
            // auto-answers the item (is_phone). v2 has no _formr_code input (v1's
            // QR source), so expose the code here, on the study origin.
            $fmrResumeCode = ($run instanceof Run && isset($run_session) && $run_session instanceof RunSession) ? $run_session->session : null;
            if ($run instanceof Run) {
                $fmrResumeUrl = run_url($run->name, null, $fmrResumeCode ? array('code' => $fmrResumeCode) : array());
                echo 'window.formr.runResumeUrl = ' . json_encode($fmrResumeUrl) . ";\n";
            }
            ?>
            <?php
            // Expose the current unit-session expiry so the v2 expiry-notifier
            // can show a "Page expired" modal when the wall clock crosses it.
            // Mirrors templates/public/head.php's logic for the v1 path.
            $expires = null;
            if (isset($run_session) && $run_session instanceof RunSession && ($us = $run_session->getCurrentUnitSession())) {
                if (!empty($us->expires)) {
                    try {
                        $expires = (new DateTime($us->expires))->format(DateTime::ATOM);
                    } catch (Exception $e) {
                        $expires = null;
                    }
                }
            }
            if ($expires):
            ?>
            window.unit_session_expires = <?php echo json_encode($expires); ?>;
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
                        <img src="<?php echo htmlspecialchars($run->header_image_path); ?>" alt="<?php echo htmlspecialchars($run->name); ?> header image">
                    </header>
                <?php endif; ?>

                <?php echo $run_content; ?>
            </div>
        </div>

        <script src="<?php echo asset_url($formBundleDir . '/js/form.bundle.js'); ?>"></script>
        <?php if ($runAssets !== '' && !empty($js[$runAssets])) { print_scripts($js[$runAssets], $runAssets); } ?>
    </body>
</html>
